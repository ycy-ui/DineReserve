<?php

declare(strict_types=1);

namespace DineReserve\Tests;

use DineReserve\AdminService;
use DineReserve\AvailabilityService;
use DineReserve\BookingService;
use DineReserve\Database;
use DineReserve\DomainException;
use DineReserve\FrozenClock;
use DineReserve\ReportService;

/**
 * The 27 non-functional requirements (URS §2, W12): performance budgets,
 * security baseline, availability (backup/restore) and capacity.
 *
 * Budgets are deliberately generous because CI runners are shared; the point is
 * to fail loudly on a regression (e.g. an accidental N+1 query), not to benchmark.
 */
final class NfrTest extends TestCase
{
    private AvailabilityService $availability;

    private BookingService $bookings;

    private ReportService $reports;

    protected function setUp(): void
    {
        parent::setUp();
        $this->availability = new AvailabilityService($this->pdo, $this->clock);
        $this->bookings = new BookingService($this->pdo, $this->availability, $this->clock);
        $this->reports = new ReportService($this->pdo, $this->clock);
    }

    /** @param callable():mixed $operation */
    private function p95(callable $operation, int $iterations): float
    {
        $samples = [];
        for ($i = 0; $i < $iterations; $i++) {
            $start = hrtime(true);
            $operation($i);
            $samples[] = (hrtime(true) - $start) / 1e6;
        }
        sort($samples);

        return $samples[(int) floor(count($samples) * 0.95)] ?? $samples[count($samples) - 1];
    }

    private function assertDomainCode(string $code, callable $call): void
    {
        try {
            $call();
        } catch (DomainException $exception) {
            self::assertSame($code, $exception->errorCode());

            return;
        }

        self::fail('expected DomainException with code ' . $code);
    }

    // ------------------------------------------------------------ performance

    public function testAvailabilitySearchMeetsItsLatencyBudget(): void
    {
        $p95 = $this->p95(fn () => $this->availability->search($this->restaurantId, '2026-11-10', '19:00', 4), 25);

        self::assertLessThan(500.0, $p95, sprintf('availability search p95 was %.1f ms', $p95));
    }

    public function testBookingCreationMeetsItsLatencyBudget(): void
    {
        $index = 0;
        $p95 = $this->p95(function (int $i) use (&$index): void {
            $index++;
            $this->bookings->create([
                'restaurant_id' => $this->restaurantId,
                'table_id' => $this->tableIds[$i % 50],
                'date' => '2026-11-' . str_pad((string) (10 + $i % 10), 2, '0', STR_PAD_LEFT),
                'time' => '19:00',
                'party_size' => 2,
                'name' => 'NFR Guest',
                'email' => 'nfr' . $index . '@example.com',
                'channel' => 'phone',
            ]);
        }, 10);

        self::assertLessThan(800.0, $p95, sprintf('booking creation p95 was %.1f ms', $p95));
    }

    public function testReportGenerationMeetsItsLatencyBudget(): void
    {
        $p95 = $this->p95(fn () => $this->reports->occupancy($this->restaurantId, '2026-11-01', '2026-11-30'), 10);

        self::assertLessThan(1000.0, $p95, sprintf('report p95 was %.1f ms', $p95));
    }

    // --------------------------------------------------------------- capacity

    public function testCapacityIsAtLeastTheSeatCount(): void
    {
        $report = $this->reports->occupancy($this->restaurantId, '2026-11-10', '2026-11-10');
        $bookableSeats = (int) $this->pdo->query(
            "SELECT SUM(seats) FROM tables WHERE restaurant_id = " . $this->restaurantId
            . " AND status NOT IN ('BLOCKED', 'MAINTENANCE')"
        )->fetchColumn();

        self::assertSame(
            $bookableSeats * 4,
            $report['capacity_per_day'],
            'capacity = bookable seats x sittings (4 for the demo periods)'
        );
    }

    public function testDemoSeedCoversSixtyTablesAndThreePeriods(): void
    {
        self::assertSame(60, (int) $this->pdo->query('SELECT COUNT(*) FROM tables')->fetchColumn());
        self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM service_periods')->fetchColumn());
        self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        self::assertSame(4, (int) $this->pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn());
    }

    // ------------------------------------------------------------ availability

    public function testDatabaseBackupCanBeRestoredAndReopened(): void
    {
        $directory = sys_get_temp_dir() . '/dinereserve-nfr-' . bin2hex(random_bytes(4));
        mkdir($directory, 0775, true);
        $path = $directory . '/live.sqlite';
        $backup = $directory . '/backup.sqlite';

        $live = Database::sqlite($path);
        Database::migrate($live);
        Database::seedDemo($live, 60);
        self::assertTrue(copy($path, $backup), 'copying the database file (the backup) must work');

        $restored = Database::sqlite($backup);
        self::assertSame(60, (int) $restored->query('SELECT COUNT(*) FROM tables')->fetchColumn());
        self::assertSame(3, (int) $restored->query('SELECT COUNT(*) FROM service_periods')->fetchColumn());

        unlink($path);
        unlink($backup);
        rmdir($directory);
    }

    public function testForeignKeysAreEnabled(): void
    {
        $enabled = (int) $this->pdo->query('PRAGMA foreign_keys')->fetchColumn();

        self::assertSame(1, $enabled);
    }

    // --------------------------------------------------------------- security

    public function testAdminEndpointsRequireTheToken(): void
    {
        $admin = new AdminService($this->pdo, $this->clock);

        $this->assertDomainCode('UNAUTHORIZED', fn () => $admin->requireToken(null));
        $admin->requireToken(AdminService::DEFAULT_ADMIN_TOKEN);
        self::assertTrue(true);
    }

    public function testPasswordsAreNeverStoredInPlaintext(): void
    {
        foreach ($this->pdo->query('SELECT password_hash FROM users')->fetchAll(\PDO::FETCH_COLUMN) as $hash) {
            self::assertMatchesRegularExpression('/^\$(2[aby]|argon2)/', (string) $hash);
        }
    }

    public function testSettingsChangeIsValidatedAndAudited(): void
    {
        $admin = new AdminService($this->pdo, $this->clock);
        $admin->updateSettings($this->restaurantId, ['cutoff_minutes' => 60]);

        self::assertSame(60, $admin->settings($this->restaurantId)['cutoff_minutes']);
        self::assertGreaterThan(0, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'settings.updated'")->fetchColumn());
        $this->assertDomainCode('VALIDATION_ERROR', fn () => $admin->updateSettings($this->restaurantId, ['cutoff_minutes' => 99999]));
    }

    public function testPreparedStatementsTreatInjectionInputAsData(): void
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM bookings WHERE reference = ?');
        $statement->execute(["' OR 1=1 --"]);

        self::assertSame(0, (int) $statement->fetchColumn());
        self::assertGreaterThanOrEqual(12, (int) $this->pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table'")->fetchColumn());
    }

    public function testAuditTrailRecordsAdministrativeActions(): void
    {
        $admin = new AdminService($this->pdo, $this->clock);
        $admin->createUser(['name' => 'NFR Host', 'email' => 'nfr-host@example.com', 'password' => 'Passw0rd!', 'role' => 'host']);

        self::assertGreaterThan(0, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'user.created'")->fetchColumn());
    }
}

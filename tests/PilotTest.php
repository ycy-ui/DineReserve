<?php

declare(strict_types=1);

namespace DineReserve\Tests;

use DineReserve\Database;
use DineReserve\PilotService;

/**
 * W13 pilot: the rehearsed dinner service must respect every operational
 * invariant a partner restaurant would check before go-live.
 */
final class PilotTest extends TestCase
{
    /** @return array<string,mixed> */
    private function runPilot(?string $backupPath = null): array
    {
        $pilot = new PilotService($this->pdo);

        return $pilot->run(PilotService::PILOT_DATE, $this->restaurantId, $backupPath);
    }

    /** @param list<array{name:string,ok:bool,detail:string}> $invariants */
    private function assertAllInvariants(?array $invariants): void
    {
        foreach ($invariants as $invariant) {
            self::assertTrue($invariant['ok'], $invariant['name'] . ': ' . $invariant['detail']);
        }
    }

    public function testThePilotServiceRunsClean(): void
    {
        $result = $this->runPilot();

        self::assertSame(PilotService::PILOT_DATE, $result['summary']['date']);
        self::assertGreaterThanOrEqual(10, $result['summary']['bookings_created']);
        self::assertGreaterThan(0, $result['summary']['covers']);
        $this->assertAllInvariants($result['invariants']);
    }

    public function testEveryCreatedBookingStillExistsOnce(): void
    {
        $result = $this->runPilot();
        $bookings = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM bookings WHERE booking_date = '" . PilotService::PILOT_DATE . "'"
        )->fetchColumn();

        self::assertSame($result['summary']['bookings_created'], $bookings);
    }

    public function testTheServiceNeverDoubleBooksATable(): void
    {
        $this->runPilot();

        $overlaps = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM bookings a JOIN bookings b
                ON a.table_id = b.table_id AND a.booking_date = b.booking_date
               AND a.id < b.id AND a.status <> 'CANCELLED' AND b.status <> 'CANCELLED'
               AND a.start_time < b.end_time AND b.start_time < a.end_time"
        )->fetchColumn();

        self::assertSame(0, $overlaps);
    }

    public function testTheReportAgreesWithTheBookingRows(): void
    {
        $result = $this->runPilot();
        $covers = (int) $this->pdo->query(
            "SELECT COALESCE(SUM(party_size), 0) FROM bookings
              WHERE booking_date = '" . PilotService::PILOT_DATE . "'
                AND status IN ('CONFIRMED','SEATED','COMPLETED')"
        )->fetchColumn();

        self::assertSame($covers, $result['report']['totals']['covers']);
        self::assertGreaterThan(0, $result['report']['capacity_per_day']);
    }

    public function testTheWaitlistProducesOffersAndResolvesThem(): void
    {
        $result = $this->runPilot();
        $resolved = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM waitlist_entries WHERE entry_date = '" . PilotService::PILOT_DATE . "'
              AND status IN ('CONVERTED','EXPIRED')"
        )->fetchColumn();

        self::assertGreaterThan(0, $result['summary']['offers']);
        self::assertGreaterThan(0, $resolved);
    }

    public function testNotificationsAreAllAccountedFor(): void
    {
        $result = $this->runPilot();
        $notifications = $result['notifications'];

        self::assertSame(
            $notifications['total'],
            $notifications['queued'] + $notifications['sent'] + $notifications['failed'] + $notifications['cancelled']
        );
        self::assertGreaterThan(0, $notifications['total']);
        self::assertSame(0, $notifications['failed'], 'the FileMailer must never fail in the rehearsal');
    }

    public function testEveryBookingChangeLeavesAnEvent(): void
    {
        $this->runPilot();
        $events = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM booking_events e JOIN bookings b ON b.id = e.booking_id
              WHERE b.booking_date = '" . PilotService::PILOT_DATE . "'"
        )->fetchColumn();

        self::assertGreaterThan(0, $events);
    }

    public function testBackupRestorePassesWhenTheDatabaseIsAFile(): void
    {
        $directory = sys_get_temp_dir() . '/dinereserve-pilot-test-' . bin2hex(random_bytes(4));
        mkdir($directory, 0775, true);
        $path = $directory . '/pilot.sqlite';
        $backup = $directory . '/pilot-backup.sqlite';

        $pdo = Database::sqlite($path);
        Database::migrate($pdo);
        $seed = Database::seedDemo($pdo, 60);

        $pilot = new PilotService($pdo);
        $result = $pilot->run(PilotService::PILOT_DATE, (int) $seed['restaurant_id'], $backup);

        $backupInvariant = array_values(array_filter(
            $result['invariants'],
            static fn (array $invariant): bool => $invariant['name'] === 'backup restores to a working copy'
        ));
        self::assertCount(1, $backupInvariant);
        self::assertTrue($backupInvariant[0]['ok'], $backupInvariant[0]['detail']);
        self::assertFileExists($backup);

        $restored = Database::sqlite($backup);
        self::assertSame(60, (int) $restored->query('SELECT COUNT(*) FROM tables')->fetchColumn());

        unlink($backup);
        unlink($path);
        rmdir($directory);
    }
}

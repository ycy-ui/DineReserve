<?php

declare(strict_types=1);

namespace DineReserve\Tests;

use DineReserve\Clock;
use DineReserve\Database;
use DineReserve\FrozenClock;
use PDO;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Every test runs against a fresh in-memory SQLite database seeded with the
 * demo restaurant (60 tables, 3 service periods) and a frozen clock so that
 * date and cut-off rules are deterministic.
 */
abstract class TestCase extends PHPUnitTestCase
{
    protected PDO $pdo;

    protected FrozenClock $clock;

    protected int $restaurantId;

    /** @var list<int> */
    protected array $tableIds;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = Database::sqlite(':memory:');
        Database::migrate($this->pdo);
        $seed = Database::seedDemo($this->pdo, 60);
        $this->restaurantId = $seed['restaurant_id'];
        $this->tableIds = $seed['tables'];
        // Monday 2 November 2026, 10:00 in the restaurant time zone.
        $this->clock = new FrozenClock('2026-11-02 10:00:00');
    }

    protected function clock(): Clock
    {
        return $this->clock;
    }

    protected function tableIdByCode(string $code): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM tables WHERE restaurant_id = ? AND code = ?');
        $stmt->execute([$this->restaurantId, $code]);
        $id = $stmt->fetchColumn();
        $this->assertNotFalse($id, "table {$code} not found");

        return (int) $id;
    }

    protected function table(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tables WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        $this->assertIsArray($row);

        return $row;
    }

    /**
     * Insert a confirmed booking directly, bypassing the service, so that the
     * availability rules can be tested against a known schedule.
     *
     * @param array<string,mixed> $overrides
     */
    protected function insertBooking(array $overrides = []): int
    {
        $tableId = $overrides['table_id'] ?? $this->tableIdByCode('T01');
        $table = $this->table((int) $tableId);
        $date = $overrides['booking_date'] ?? '2026-11-10';
        $start = $overrides['start_time'] ?? '19:00';
        $end = $overrides['end_time'] ?? '20:45';
        $now = $this->clock->now()->format('Y-m-d\TH:i:sP');

        $stmt = $this->pdo->prepare(
            'INSERT INTO bookings
             (reference, restaurant_id, table_id, booking_date, start_time, end_time, party_size,
              status, guest_name, guest_email, cutoff_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $overrides['reference'] ?? 'BK' . strtoupper(bin2hex(random_bytes(3))),
            $this->restaurantId,
            (int) $tableId,
            $date,
            $start,
            $end,
            $overrides['party_size'] ?? 2,
            $overrides['status'] ?? 'CONFIRMED',
            'Test Guest',
            'guest@example.com',
            $overrides['cutoff_at'] ?? '2026-11-10T17:00:00+08:00',
            $now,
            $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}

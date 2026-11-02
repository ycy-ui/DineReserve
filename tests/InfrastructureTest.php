<?php

declare(strict_types=1);

namespace DineReserve\Tests;

use DineReserve\Database;
use DineReserve\FrozenClock;
use DineReserve\Slot;

/**
 * Guards the shared infrastructure the two feature streams depend on:
 * the schema of both dialects, the demo seed and the time arithmetic.
 */
final class InfrastructureTest extends TestCase
{
    public function testSchemaCreatesEveryTable(): void
    {
        $tables = $this->pdo
            ->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
            ->fetchAll(\PDO::FETCH_COLUMN);

        $expected = [
            'audit_log', 'booking_events', 'bookings', 'idempotency_keys', 'notification_log',
            'restaurants', 'roles', 'service_periods', 'tables', 'user_roles', 'users', 'waitlist_entries',
        ];

        self::assertSame($expected, $tables);
    }

    public function testDemoSeedMatchesYearOneCapacity(): void
    {
        self::assertCount(60, $this->tableIds);

        $periods = $this->pdo
            ->query('SELECT name, start_time, end_time, avg_dining_minutes FROM service_periods ORDER BY id')
            ->fetchAll();

        self::assertSame(['Lunch', 'Afternoon Tea', 'Dinner'], array_column($periods, 'name'));
        self::assertSame('11:30', $periods[0]['start_time']);
        self::assertSame('22:00', $periods[2]['end_time']);

        $blocked = (int) $this->pdo->query("SELECT COUNT(*) FROM tables WHERE status = 'BLOCKED'")->fetchColumn();
        $maintenance = (int) $this->pdo->query("SELECT COUNT(*) FROM tables WHERE status = 'MAINTENANCE'")->fetchColumn();
        self::assertSame(3, $blocked, 'T17/T34/T51 must be out of service');
        self::assertSame(2, $maintenance, 'T23/T46 must be in maintenance');

        self::assertSame(4, (int) $this->pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn());
        self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function testTableCapacitiesCycleAndAreUnique(): void
    {
        $counts = $this->pdo
            ->query('SELECT seats, COUNT(*) AS n FROM tables GROUP BY seats ORDER BY seats')
            ->fetchAll();

        self::assertSame(
            [['seats' => 2, 'n' => 15], ['seats' => 4, 'n' => 15], ['seats' => 6, 'n' => 15], ['seats' => 8, 'n' => 15]],
            $counts
        );
    }

    public function testSplitStatementsStripsComments(): void
    {
        $sql = "-- a comment\nCREATE TABLE a (id INT);\n-- another\n\nCREATE INDEX i ON a (id);\n";
        $statements = Database::splitStatements($sql);

        self::assertCount(2, $statements);
        self::assertStringStartsWith('CREATE TABLE a', $statements[0]);
        self::assertStringStartsWith('CREATE INDEX i', $statements[1]);
    }

    public function testIsEmptyDetectsSeededAndFreshDatabases(): void
    {
        self::assertFalse(Database::isEmpty($this->pdo));

        $fresh = Database::sqlite(':memory:');
        Database::migrate($fresh);
        self::assertTrue(Database::isEmpty($fresh));
    }

    /**
     * @dataProvider timeArithmeticProvider
     */
    public function testSlotArithmetic(string $time, int $add, string $expected): void
    {
        self::assertSame($expected, Slot::addMinutes($time, $add));
    }

    /** @return iterable<string,array{string,int,string}> */
    public static function timeArithmeticProvider(): iterable
    {
        yield 'plain add' => ['19:00', 135, '21:15'];
        yield 'cross hour' => ['11:30', 105, '13:15'];
        yield 'cross midnight' => ['23:30', 45, '24:15'];
        yield 'zero' => ['23:59', 0, '23:59'];
    }

    public function testOverlapIsHalfOpen(): void
    {
        self::assertTrue(Slot::overlaps('19:00', '21:15', '20:00', '22:00'));
        // 17:30-19:00 ends exactly when 19:00-21:15 starts: back-to-back, no overlap.
        self::assertFalse(Slot::overlaps('19:00', '21:15', '17:30', '19:00'));
        // 21:15-23:30 starts exactly when 19:00-21:15 ends: no overlap.
        self::assertFalse(Slot::overlaps('19:00', '21:15', '21:15', '23:30'));
    }

    public function testDateValidationIsStrict(): void
    {
        self::assertTrue(Slot::isValidDate('2026-11-10'));
        self::assertFalse(Slot::isValidDate('2026-2-3'));
        self::assertFalse(Slot::isValidDate('2026-02-30'));
        self::assertFalse(Slot::isValidDate('10/11/2026'));
    }

    public function testFrozenClockCanAdvance(): void
    {
        $clock = new FrozenClock('2026-11-02 10:00:00');
        self::assertSame('2026-11-02T10:00:00+08:00', $clock->now()->format('Y-m-d\TH:i:sP'));
        $clock->advance('+2 hours');
        self::assertSame('2026-11-02T12:00:00+08:00', $clock->now()->format('Y-m-d\TH:i:sP'));
    }
}

<?php

declare(strict_types=1);

namespace DineReserve;

use PDO;
use RuntimeException;

/**
 * Connection factory, schema loader and demo seeder.
 *
 * The booking engine is database agnostic: it only relies on PDO + SQL that
 * both SQLite (test suite, local dev) and MySQL 8.0 (TastyIgniter deployment)
 * understand.
 */
final class Database
{
    public const SCHEMA_SQLITE = 'schema.sql';
    public const SCHEMA_MYSQL = 'schema-mysql.sql';

    public static function sqlite(string $path = ':memory:'): PDO
    {
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        return $pdo;
    }

    public static function mysql(
        string $host,
        string $database,
        string $user,
        string $password,
        int $port = 3306
    ): PDO {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database);

        return new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    public static function driver(PDO $pdo): string
    {
        return (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public static function schemaFileFor(string $driver): string
    {
        $file = $driver === 'sqlite' ? self::SCHEMA_SQLITE : self::SCHEMA_MYSQL;
        $path = dirname(__DIR__) . '/database/' . $file;
        if (!is_file($path)) {
            throw new RuntimeException("Schema file not found: {$path}");
        }

        return $path;
    }

    public static function migrate(PDO $pdo, ?string $schemaFile = null): void
    {
        $file = $schemaFile ?? self::schemaFileFor(self::driver($pdo));
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException("Unable to read schema: {$file}");
        }

        foreach (self::splitStatements($sql) as $statement) {
            $pdo->exec($statement);
        }
    }

    /**
     * Split a DDL file into single statements.
     * The project SQL contains only line comments and no procedural bodies,
     * so a semicolon split after stripping comment lines is safe.
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $sql) ?: [] as $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }
            $lines[] = $line;
        }

        $statements = [];
        foreach (explode(';', implode("\n", $lines)) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $statements[] = $part;
            }
        }

        return $statements;
    }

    public static function isEmpty(PDO $pdo): bool
    {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM restaurants')->fetchColumn();

        return $count === 0;
    }

    /**
     * Deterministic demo data: one restaurant, three service periods and
     * 60 tables, mirroring the year-one capacity figures of the URS (CAP-1.1).
     *
     * @return array{restaurant_id:int,tables:list<int>,periods:array<string,int>}
     */
    public static function seedDemo(PDO $pdo, int $tableCount = 60, ?string $now = null): array
    {
        $now ??= (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

        $pdo->prepare(
            'INSERT INTO restaurants (name, address, timezone, currency, languages, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            'DineReserve Demo Restaurant',
            'Rua de Nam Keng, Taipa, Macau',
            'Asia/Macau',
            'MOP',
            'en,zh',
            $now,
        ]);
        $restaurantId = (int) $pdo->lastInsertId();

        $periods = [
            ['Lunch', '11:30', '14:30', 90],
            ['Afternoon Tea', '15:00', '17:00', 60],
            ['Dinner', '17:30', '22:00', 120],
        ];
        $periodIds = [];
        $insertPeriod = $pdo->prepare(
            'INSERT INTO service_periods (restaurant_id, name, start_time, end_time, avg_dining_minutes, days_of_week)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($periods as [$name, $start, $end, $dining]) {
            $insertPeriod->execute([$restaurantId, $name, $start, $end, $dining, '1,2,3,4,5,6,7']);
            $periodIds[$name] = (int) $pdo->lastInsertId();
        }

        $capacities = [2, 4, 6, 8];
        $areas = ['main', 'window', 'terrace'];
        $insertTable = $pdo->prepare(
            'INSERT INTO tables (restaurant_id, code, seats, area, pos_x, pos_y, status)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $tableIds = [];
        for ($i = 1; $i <= $tableCount; $i++) {
            $code = sprintf('T%02d', $i);
            $seats = $capacities[($i - 1) % count($capacities)];
            $area = $areas[(int) floor(($i - 1) / count($capacities)) % count($areas)];
            // A few tables are out of service on purpose: the availability engine
            // must never offer them (REQ-1.1 "blocked for maintenance").
            $status = match (true) {
                $i % 17 === 0 => 'BLOCKED',
                $i % 23 === 0 => 'MAINTENANCE',
                default => 'FREE',
            };
            $insertTable->execute([
                $restaurantId,
                $code,
                $seats,
                $area,
                1 + (($i - 1) % 10),
                1 + (int) floor(($i - 1) / 10),
                $status,
            ]);
            $tableIds[] = (int) $pdo->lastInsertId();
        }

        $existingRoles = (int) $pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn();
        $insertRole = $pdo->prepare('INSERT INTO roles (code, name) VALUES (?, ?)');
        foreach ($existingRoles > 0 ? [] : [
            ['diner', 'Diner'],
            ['host', 'Host / Front desk'],
            ['manager', 'Restaurant manager'],
            ['administrator', 'System administrator'],
        ] as [$code, $name]) {
            $insertRole->execute([$code, $name]);
        }

        $insertUser = $pdo->prepare(
            'INSERT INTO users (restaurant_id, name, email, password_hash, is_active, created_at)
             VALUES (?, ?, ?, ?, 1, ?)'
        );
        $hash = password_hash('DemoPass123!', PASSWORD_DEFAULT);
        foreach ([
            ['Demo Manager', 'manager@dinereserve.local'],
            ['Demo Host', 'host@dinereserve.local'],
            ['Demo Admin', 'admin@dinereserve.local'],
        ] as [$name, $email]) {
            $insertUser->execute([$restaurantId, $name, $email, $hash, $now]);
        }

        return [
            'restaurant_id' => $restaurantId,
            'tables' => $tableIds,
            'periods' => $periodIds,
        ];
    }
}

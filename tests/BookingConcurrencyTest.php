<?php

declare(strict_types=1);

namespace DineReserve\Tests;

use DineReserve\AvailabilityService;
use DineReserve\BookingService;
use DineReserve\Database;
use DineReserve\DomainException;
use DineReserve\FrozenClock;
use PDO;
use Throwable;

/**
 * Proves REQ-1.2 "same table can never be double booked" against a real
 * database file and two real OS processes.
 *
 * The two children are released at the same instant through a barrier file;
 * each opens its own SQLite connection and calls BookingService::create().
 * Exactly one may commit, the other must receive TABLE_TAKEN (the loser only
 * starts its write transaction after the winner committed, thanks to
 * BEGIN IMMEDIATE), and the table must end up with a single booking.
 */
final class BookingConcurrencyTest extends TestCase
{
    private const EXIT_SUCCESS = 0;

    private const EXIT_TABLE_TAKEN = 3;

    private const EXIT_OTHER_DOMAIN_ERROR = 4;

    private const EXIT_FATAL = 5;

    private const EXIT_BARRIER_TIMEOUT = 9;

    private ?string $databaseFile = null;

    protected function tearDown(): void
    {
        if ($this->databaseFile !== null) {
            @unlink($this->databaseFile);
            @unlink($this->databaseFile . '.barrier');
            @unlink($this->databaseFile . '-journal');
            @unlink($this->databaseFile . '-wal');
            @unlink($this->databaseFile . '-shm');
        }

        parent::tearDown();
    }

    public function testTwoConcurrentDinersCannotBookTheSameTable(): void
    {
        $this->skipWithoutPcntl();

        [$databaseFile, $restaurantId, $tableId] = $this->seedFileDatabase();
        $input = [
            'restaurant_id' => $restaurantId,
            'table_id' => $tableId,
            'date' => '2026-11-10',
            'time' => '19:00',
            'party_size' => 6,
            'name' => 'Chan Tai Man',
            'email' => 'chan@example.com',
        ];

        $exitCodes = $this->runConcurrently($databaseFile, [$input, $input]);
        self::assertSame(
            [self::EXIT_SUCCESS, self::EXIT_TABLE_TAKEN],
            $exitCodes,
            'Exactly one diner must get the table, the other one TABLE_TAKEN.'
        );

        $pdo = Database::sqlite($databaseFile);
        $bookings = (int) $pdo
            ->query("SELECT COUNT(*) FROM bookings WHERE table_id = {$tableId} AND booking_date = '2026-11-10'")
            ->fetchColumn();
        self::assertSame(1, $bookings, 'The table may only hold one booking for that sitting.');

        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM bookings')->fetchColumn());
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM booking_events')->fetchColumn());
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM notification_log')->fetchColumn());
    }

    public function testConcurrentDoubleSubmitWithTheSameIdempotencyTokenBooksOnce(): void
    {
        $this->skipWithoutPcntl();

        [$databaseFile, $restaurantId, $tableId] = $this->seedFileDatabase();
        $input = [
            'restaurant_id' => $restaurantId,
            'table_id' => $tableId,
            'date' => '2026-11-10',
            'time' => '19:00',
            'party_size' => 6,
            'name' => 'Chan Tai Man',
            'email' => 'chan@example.com',
            'idempotency_token' => 'double-submit-0001',
        ];

        $exitCodes = $this->runConcurrently($databaseFile, [$input, $input]);
        self::assertSame([self::EXIT_SUCCESS, self::EXIT_SUCCESS], $exitCodes);

        $pdo = Database::sqlite($databaseFile);
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM bookings')->fetchColumn());
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM idempotency_keys')->fetchColumn());
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM booking_events')->fetchColumn());
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM notification_log')->fetchColumn());
    }

    private function skipWithoutPcntl(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('The pcntl extension is required for the real concurrency test.');
        }
    }

    /**
     * Build a file-backed SQLite database (an in-memory one cannot be shared
     * with forked processes) and detach the parent connection before forking.
     *
     * @return array{0:string,1:int,2:int} database file, restaurant id, T03 table id
     */
    private function seedFileDatabase(): array
    {
        $file = tempnam(sys_get_temp_dir(), 'dinereserve_booking_');
        if ($file === false) {
            self::fail('Unable to create a temporary database file.');
        }
        $this->databaseFile = $file;

        $pdo = Database::sqlite($file);
        Database::migrate($pdo);
        $seed = Database::seedDemo($pdo, 60);

        $statement = $pdo->prepare("SELECT id FROM tables WHERE restaurant_id = ? AND code = 'T03'");
        $statement->execute([$seed['restaurant_id']]);
        $tableId = (int) $statement->fetchColumn();

        // Close the parent connection so the children are the only writers.
        $pdo = null;

        return [$file, $seed['restaurant_id'], $tableId];
    }

    /**
     * Fork one child per input, release them together and collect exit codes.
     *
     * @param list<array<string,mixed>> $inputs
     *
     * @return list<int> sorted exit codes
     */
    private function runConcurrently(string $databaseFile, array $inputs): array
    {
        $barrierFile = $databaseFile . '.barrier';
        $pids = [];

        foreach ($inputs as $input) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                self::fail('Unable to fork the child processes.');
            }

            if ($pid === 0) {
                // Child process: never return to PHPUnit.
                exit($this->runChild($databaseFile, $barrierFile, $input));
            }

            $pids[] = $pid;
        }

        // Release both children at the same instant.
        file_put_contents($barrierFile, 'go');

        $exitCodes = [];
        foreach ($pids as $pid) {
            $status = 0;
            $waited = pcntl_waitpid($pid, $status);
            self::assertSame($pid, $waited);
            self::assertTrue(pcntl_wifexited($status), 'A child process was killed by a signal instead of exiting.');
            $exitCodes[] = pcntl_wexitstatus($status);
        }

        @unlink($barrierFile);
        sort($exitCodes);

        return $exitCodes;
    }

    /**
     * @param array<string,mixed> $input
     */
    private function runChild(string $databaseFile, string $barrierFile, array $input): int
    {
        // The child inherited the parent's output buffers; drop them so an
        // exit() inside the child cannot flush the parent's captured output.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $deadline = microtime(true) + 5.0;
        while (!is_file($barrierFile)) {
            if (microtime(true) > $deadline) {
                return self::EXIT_BARRIER_TIMEOUT;
            }
            usleep(1000);
        }

        try {
            $pdo = Database::sqlite($databaseFile);
            $service = new BookingService($pdo, new AvailabilityService($pdo), new FrozenClock('2026-11-02 10:00:00'));
            $service->create($input);

            return self::EXIT_SUCCESS;
        } catch (DomainException $exception) {
            if ($exception->errorCode() === 'TABLE_TAKEN') {
                return self::EXIT_TABLE_TAKEN;
            }

            fwrite(STDERR, 'child domain error: ' . $exception->errorCode() . PHP_EOL);

            return self::EXIT_OTHER_DOMAIN_ERROR;
        } catch (Throwable $exception) {
            fwrite(STDERR, 'child error: ' . $exception->getMessage() . PHP_EOL);

            return self::EXIT_FATAL;
        }
    }
}

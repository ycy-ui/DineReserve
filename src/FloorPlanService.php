<?php

declare(strict_types=1);

namespace DineReserve;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * Floor plan and table-status management for URS REQ-1.6:
 *
 *  - a live floor plan (tables, areas, current/upcoming bookings) that a host
 *    screen can poll every five seconds,
 *  - check-in (`CONFIRMED → SEATED`), walk-in seating, no-show and release
 *    (`SEATED → COMPLETED`, `CLEANING → FREE`),
 *  - every transition appends a `booking_events` row and an `audit_log` row.
 */
final class FloorPlanService
{
    /** A host screen refreshes the floor plan this often (URS REQ-1.6 §11). */
    public const POLL_SECONDS = 5;

    public const TABLE_FREE = 'FREE';
    public const TABLE_OCCUPIED = 'OCCUPIED';
    public const TABLE_CLEANING = 'CLEANING';
    public const TABLE_BLOCKED = 'BLOCKED';
    public const TABLE_MAINTENANCE = 'MAINTENANCE';

    private PDO $pdo;

    private AvailabilityService $availability;

    private Clock $clock;

    private ?BookingService $bookings;

    public function __construct(
        PDO $pdo,
        ?AvailabilityService $availability = null,
        ?Clock $clock = null,
        ?BookingService $bookings = null
    ) {
        $this->pdo = $pdo;
        $this->availability = $availability ?? new AvailabilityService($pdo, $clock);
        $this->clock = $clock ?? new SystemClock();
        $this->bookings = $bookings;
    }

    /**
     * Snapshot of the floor for a restaurant/date/time.
     *
     * @return array<string,mixed>
     */
    public function snapshot(int $restaurantId, string $date, ?string $time = null): array
    {
        $restaurant = $this->findRestaurant($restaurantId);
        if ($restaurant === false) {
            throw DomainException::notFound('Restaurant not found.');
        }
        if (!Slot::isValidDate($date)) {
            throw DomainException::validation(['date' => 'The date must be a real date in YYYY-MM-DD format.']);
        }
        $timezone = new DateTimeZone((string) $restaurant['timezone']);
        $now = $this->clock->now()->setTimezone($timezone);
        $time = $time === null || !Slot::isValidTime($time) ? $now->format('H:i') : $time;

        $tables = $this->pdo->prepare(
            'SELECT * FROM tables WHERE restaurant_id = ? ORDER BY area, code'
        );
        $tables->execute([$restaurantId]);
        $tableRows = $tables->fetchAll(PDO::FETCH_ASSOC);

        $bookings = $this->pdo->prepare(
            'SELECT * FROM bookings
              WHERE restaurant_id = ? AND booking_date = ? AND status IN (?, ?)
              ORDER BY start_time'
        );
        $bookings->execute([$restaurantId, $date, 'CONFIRMED', 'SEATED']);
        $byTable = [];
        foreach ($bookings->fetchAll(PDO::FETCH_ASSOC) as $booking) {
            $byTable[(int) $booking['table_id']][] = $booking;
        }

        $summary = [
            'free' => 0,
            'occupied' => 0,
            'cleaning' => 0,
            'blocked' => 0,
            'maintenance' => 0,
            'booked_today' => 0,
        ];
        $tablesPayload = [];
        foreach ($tableRows as $table) {
            $tableId = (int) $table['id'];
            $current = null;
            $upcoming = null;
            foreach ($byTable[$tableId] ?? [] as $booking) {
                $start = (string) $booking['start_time'];
                $end = (string) $booking['end_time'];
                if ($current === null && $start <= $time && $end > $time) {
                    $current = $booking;

                    continue;
                }
                if ($upcoming === null && $start >= $time) {
                    $upcoming = $booking;
                }
            }

            $status = (string) $table['status'];
            $summary[match ($status) {
                self::TABLE_OCCUPIED => 'occupied',
                self::TABLE_CLEANING => 'cleaning',
                self::TABLE_BLOCKED => 'blocked',
                self::TABLE_MAINTENANCE => 'maintenance',
                default => 'free',
            }]++;
            if ($current !== null || $upcoming !== null) {
                $summary['booked_today']++;
            }

            $tablesPayload[] = [
                'id' => $tableId,
                'code' => (string) $table['code'],
                'seats' => (int) $table['seats'],
                'area' => (string) $table['area'],
                'position' => ['x' => (int) $table['pos_x'], 'y' => (int) $table['pos_y']],
                'status' => $status,
                'seated' => $current !== null && strtoupper((string) $current['status']) === 'SEATED',
                'current_booking' => $current === null ? null : $this->bookingSummary($current, $timezone),
                'upcoming_booking' => $upcoming === null ? null : $this->bookingSummary($upcoming, $timezone),
            ];
        }

        return [
            'restaurant' => [
                'id' => (int) $restaurant['id'],
                'name' => (string) $restaurant['name'],
                'timezone' => (string) $restaurant['timezone'],
            ],
            'date' => $date,
            'time' => $time,
            'generated_at' => Slot::iso8601($now),
            'poll_after_seconds' => self::POLL_SECONDS,
            'summary' => $summary,
            'tables' => $tablesPayload,
        ];
    }

    /**
     * Check a diner in: CONFIRMED → SEATED, table FREE → OCCUPIED.
     *
     * @return array{booking:array<string,mixed>,table:array<string,mixed>}
     */
    public function seat(string $reference): array
    {
        $booking = $this->findBooking($reference);
        $status = strtoupper((string) $booking['status']);
        if ($status === 'SEATED') {
            throw new DomainException('ALREADY_SEATED', 'This booking is already seated.', ['status' => $status], 409);
        }
        if ($status !== 'CONFIRMED') {
            throw new DomainException(
                'BOOKING_NOT_SEATABLE',
                'Only a confirmed booking can be seated.',
                ['status' => $status],
                409
            );
        }

        $tableId = (int) $booking['table_id'];
        $table = $this->findTable($tableId);
        if ($table === false) {
            throw DomainException::notFound('Table not found.');
        }
        if (!in_array(strtoupper((string) $table['status']), [self::TABLE_FREE, self::TABLE_OCCUPIED], true)) {
            throw new DomainException(
                'TABLE_UNAVAILABLE',
                'The table is blocked or under maintenance.',
                ['table_id' => $tableId, 'status' => (string) $table['status']],
                409
            );
        }

        $nowIso = $this->nowIsoForBooking($booking);
        $this->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE tables SET status = ? WHERE id = ?')
                ->execute([self::TABLE_OCCUPIED, $tableId]);
            $this->pdo->prepare('UPDATE bookings SET status = ?, updated_at = ? WHERE id = ?')
                ->execute(['SEATED', $this->storageDateTime($nowIso), (int) $booking['id']]);
            $this->insertEvent((int) $booking['id'], 'seated', [
                'reference' => (string) $booking['reference'],
                'table_id' => $tableId,
            ], $nowIso);
            $this->commit();
        } catch (Throwable $exception) {
            $this->rollBack();

            throw $exception;
        }

        $this->audit('floorplan.seat', 'booking', (string) $booking['reference'], ['table_id' => $tableId]);
        $this->invalidateQuietly((int) $booking['restaurant_id'], (string) $booking['booking_date']);

        return [
            'booking' => $this->bookingSummary($this->findBooking($reference), $this->timezoneFor($booking)),
            'table' => $this->tableSummary($tableId),
        ];
    }

    /**
     * Seat a walk-in guest: a booking is created with `channel = walk_in` and
     * immediately moved to SEATED.
     *
     * @param array<string,mixed> $input restaurant_id, table_id, party_size, name, email?, time?
     * @return array{booking:array<string,mixed>,table:array<string,mixed>}
     */
    public function walkIn(array $input): array
    {
        if ($this->bookings === null) {
            throw new DomainException('FLOORPLAN_ERROR', 'The booking service is not wired.', [], 500);
        }

        $restaurantId = $this->positiveIntOrNull($input['restaurant_id'] ?? null);
        $tableId = $this->positiveIntOrNull($input['table_id'] ?? null);
        $partySize = $this->positiveIntOrNull($input['party_size'] ?? null);
        $name = is_string($input['name'] ?? null) ? trim((string) $input['name']) : '';

        $errors = [];
        if ($restaurantId === null) {
            $errors['restaurant_id'] = 'A restaurant is required.';
        }
        if ($tableId === null) {
            $errors['table_id'] = 'A table is required.';
        }
        if ($partySize === null) {
            $errors['party_size'] = 'The party size must be a whole number greater than zero.';
        }
        if ($name === '') {
            $errors['name'] = 'A guest name is required.';
        }
        if ($errors !== []) {
            throw DomainException::validation($errors);
        }

        $restaurant = $this->findRestaurant((int) $restaurantId);
        if ($restaurant === false) {
            throw DomainException::notFound('Restaurant not found.');
        }
        $timezone = new DateTimeZone((string) $restaurant['timezone']);
        $now = $this->clock->now()->setTimezone($timezone);
        $date = $now->format('Y-m-d');
        $time = isset($input['time']) && Slot::isValidTime((string) $input['time'])
            ? (string) $input['time']
            : Slot::format(((intdiv($now->format('G') * 60 + (int) $now->format('i'), 15) + 1) * 15));

        $email = is_string($input['email'] ?? null) && trim((string) $input['email']) !== ''
            ? mb_strtolower(trim((string) $input['email']))
            : 'walkin@dinereserve.invalid';

        $result = $this->bookings->create([
            'restaurant_id' => (int) $restaurantId,
            'table_id' => (int) $tableId,
            'date' => $date,
            'time' => $time,
            'party_size' => (int) $partySize,
            'name' => $name,
            'email' => $email,
            'channel' => 'walk_in',
            'special_requests' => 'Walk-in seated at the host desk.',
        ]);

        $seated = $this->seat((string) $result['booking']['reference']);
        $this->audit('floorplan.walk_in', 'booking', (string) $result['booking']['reference'], [
            'table_id' => (int) $tableId,
            'party_size' => (int) $partySize,
        ]);

        return $seated;
    }

    /**
     * Mark a no-show: booking CONFIRMED → NO_SHOW, table → CLEANING.
     *
     * @return array{booking:array<string,mixed>,table:array<string,mixed>}
     */
    public function noShow(string $reference): array
    {
        $booking = $this->findBooking($reference);
        $status = strtoupper((string) $booking['status']);
        if ($status === 'SEATED') {
            throw new DomainException('BOOKING_NOT_NO_SHOW', 'A seated guest cannot be marked as a no-show.', [], 409);
        }
        if ($status !== 'CONFIRMED') {
            throw new DomainException(
                'BOOKING_NOT_NO_SHOW',
                'Only a confirmed booking can be marked as a no-show.',
                ['status' => $status],
                409
            );
        }

        $tableId = (int) $booking['table_id'];
        $nowIso = $this->nowIsoForBooking($booking);
        $this->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE bookings SET status = ?, updated_at = ? WHERE id = ?')
                ->execute(['NO_SHOW', $this->storageDateTime($nowIso), (int) $booking['id']]);
            $this->pdo->prepare('UPDATE tables SET status = ? WHERE id = ?')
                ->execute([self::TABLE_CLEANING, $tableId]);
            $this->insertEvent((int) $booking['id'], 'no_show', [
                'reference' => (string) $booking['reference'],
                'table_id' => $tableId,
            ], $nowIso);
            $this->commit();
        } catch (Throwable $exception) {
            $this->rollBack();

            throw $exception;
        }

        $this->audit('floorplan.no_show', 'booking', (string) $booking['reference'], ['table_id' => $tableId]);
        $this->invalidateQuietly((int) $booking['restaurant_id'], (string) $booking['booking_date']);

        return [
            'booking' => $this->bookingSummary($this->findBooking($reference), $this->timezoneFor($booking)),
            'table' => $this->tableSummary($tableId),
        ];
    }

    /**
     * Release a table: OCCUPIED|CLEANING → FREE, and a still-seated booking
     * on it becomes COMPLETED.
     *
     * @return array{table:array<string,mixed>,completed_booking:array<string,mixed>|null}
     */
    public function release(int $tableId, ?string $date = null): array
    {
        $table = $this->findTable($tableId);
        if ($table === false) {
            throw DomainException::notFound('Table not found.');
        }
        if (in_array(strtoupper((string) $table['status']), [self::TABLE_BLOCKED, self::TABLE_MAINTENANCE], true)) {
            throw new DomainException(
                'TABLE_NOT_RELEASABLE',
                'A blocked or maintenance table cannot be released.',
                ['status' => (string) $table['status']],
                409
            );
        }

        $restaurant = $this->findRestaurant((int) $table['restaurant_id']);
        $timezone = new DateTimeZone($restaurant === false ? 'Asia/Macau' : (string) $restaurant['timezone']);
        $date = $date === null || !Slot::isValidDate($date) ? $this->clock->now()->setTimezone($timezone)->format('Y-m-d') : $date;

        $active = $this->findActiveBookingOnTable($tableId, $date);
        $nowIso = Slot::iso8601($this->clock->now()->setTimezone($timezone));

        $this->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE tables SET status = ? WHERE id = ?')
                ->execute([self::TABLE_FREE, $tableId]);
            if ($active !== false) {
                $this->pdo->prepare('UPDATE bookings SET status = ?, updated_at = ? WHERE id = ?')
                    ->execute(['COMPLETED', $this->storageDateTime($nowIso), (int) $active['id']]);
                $this->insertEvent((int) $active['id'], 'released', [
                    'reference' => (string) $active['reference'],
                    'table_id' => $tableId,
                    'outcome' => 'COMPLETED',
                ], $nowIso);
            }
            $this->commit();
        } catch (Throwable $exception) {
            $this->rollBack();

            throw $exception;
        }

        $this->audit('floorplan.release', 'table', (string) $tableId, [
            'completed_booking' => $active === false ? null : (string) $active['reference'],
        ]);
        $this->invalidateQuietly((int) $table['restaurant_id'], $date);

        $completed = $active === false ? null : $this->bookingSummary($active, $timezone);
        if ($completed !== null) {
            $completed['status'] = 'COMPLETED';
        }

        return [
            'table' => $this->tableSummary($tableId),
            'completed_booking' => $completed,
        ];
    }

    // ------------------------------------------------------------ helpers

    /** @return array<string,mixed> */
    private function findBooking(string $reference): array
    {
        $reference = strtoupper(trim($reference));
        if ($reference === '') {
            throw DomainException::validation(['reference' => 'A booking reference is required.']);
        }
        $statement = $this->pdo->prepare('SELECT * FROM bookings WHERE reference = ?');
        $statement->execute([$reference]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw DomainException::notFound('Booking not found.');
        }

        return $row;
    }

    /** @return array<string,mixed>|false */
    private function findActiveBookingOnTable(int $tableId, string $date): array|false
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM bookings
              WHERE table_id = ? AND booking_date = ? AND status = ?
              ORDER BY start_time LIMIT 1'
        );
        $statement->execute([$tableId, $date, 'SEATED']);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : false;
    }

    /** @return array<string,mixed>|false */
    private function findTable(int $tableId): array|false
    {
        $statement = $this->pdo->prepare('SELECT * FROM tables WHERE id = ?');
        $statement->execute([$tableId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : false;
    }

    /** @return array<string,mixed>|false */
    private function findRestaurant(int $restaurantId): array|false
    {
        $statement = $this->pdo->prepare('SELECT * FROM restaurants WHERE id = ?');
        $statement->execute([$restaurantId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : false;
    }

    private function timezoneFor(array $booking): DateTimeZone
    {
        $restaurant = $this->findRestaurant((int) $booking['restaurant_id']);

        return new DateTimeZone($restaurant === false ? 'Asia/Macau' : (string) $restaurant['timezone']);
    }

    private function nowIsoForBooking(array $booking): string
    {
        return Slot::iso8601($this->clock->now()->setTimezone($this->timezoneFor($booking)));
    }

    /** @return array<string,mixed> */
    private function bookingSummary(array $booking, DateTimeZone $timezone): array
    {
        $start = Slot::at((string) $booking['booking_date'], (string) $booking['start_time'], $timezone);

        return [
            'id' => (int) $booking['id'],
            'reference' => (string) $booking['reference'],
            'status' => (string) $booking['status'],
            'date' => (string) $booking['booking_date'],
            'start' => (string) $booking['start_time'],
            'end' => (string) $booking['end_time'],
            'party_size' => (int) $booking['party_size'],
            'guest_name' => (string) $booking['guest_name'],
            'channel' => (string) $booking['channel'],
            'created_at' => (string) $this->isoFromDatabase((string) $booking['created_at'], $timezone),
            'start_iso' => Slot::iso8601($start),
        ];
    }

    /** @return array<string,mixed> */
    private function tableSummary(int $tableId): array
    {
        $table = $this->findTable($tableId);
        if ($table === false) {
            throw DomainException::notFound('Table not found.');
        }

        return [
            'id' => (int) $table['id'],
            'code' => (string) $table['code'],
            'seats' => (int) $table['seats'],
            'area' => (string) $table['area'],
            'status' => (string) $table['status'],
        ];
    }

    /** @param array<string,mixed> $payload */
    private function insertEvent(int $bookingId, string $type, array $payload, string $nowIso): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO booking_events (booking_id, event_type, actor, payload, occurred_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $bookingId,
            $type,
            'host',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $this->storageDateTime($nowIso),
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function audit(string $action, string $entity, string $entityId, array $payload): void
    {
        try {
            $this->pdo->prepare(
                'INSERT INTO audit_log (actor, action, entity, entity_id, payload, occurred_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                'host',
                $action,
                $entity,
                $entityId,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                $this->storageDateTime(Slot::iso8601($this->clock->now())),
            ]);
        } catch (Throwable) {
            // Audit is best effort.
        }
    }

    private function invalidateQuietly(int $restaurantId, string $date): void
    {
        try {
            $this->availability->invalidate($restaurantId, $date);
        } catch (Throwable) {
            // Cache invalidation must never fail a committed transition.
        }
    }

    private function beginTransaction(): void
    {
        if (Database::driver($this->pdo) === 'sqlite') {
            $this->pdo->exec('BEGIN IMMEDIATE');

            return;
        }

        $this->pdo->beginTransaction();
    }

    private function commit(): void
    {
        if (Database::driver($this->pdo) === 'sqlite') {
            $this->pdo->exec('COMMIT');

            return;
        }

        $this->pdo->commit();
    }

    private function rollBack(): void
    {
        try {
            if (Database::driver($this->pdo) === 'sqlite') {
                $this->pdo->exec('ROLLBACK');

                return;
            }
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        } catch (Throwable) {
            // Nothing to roll back.
        }
    }

    private function storageDateTime(string $iso): string
    {
        return Database::dateTimeFor($this->pdo, $iso);
    }

    private function isoFromDatabase(string $value, DateTimeZone $timezone): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $value) === 1) {
            return $value;
        }
        try {
            return Slot::iso8601((new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone($timezone));
        } catch (Throwable) {
            return $value;
        }
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            $int = (int) trim($value);

            return $int > 0 ? $int : null;
        }

        return null;
    }
}

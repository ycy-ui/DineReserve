<?php

declare(strict_types=1);

namespace DineReserve;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use Throwable;

/**
 * Creates a table booking (URS REQ-1.2).
 *
 * The service is the only place allowed to write a booking: it validates the
 * request, protects against double submits (idempotency token), takes a write
 * lock that makes two concurrent diners unable to grab the same table, and
 * writes the booking, its audit event, the idempotency key and the simulated
 * confirmation e-mail in one transaction (all-or-nothing).
 *
 * SQLite uses `BEGIN IMMEDIATE` (PDO's default DEFERRED transaction would let
 * two readers both see the table as free); MySQL additionally locks the table
 * row with `SELECT ... FOR UPDATE`.
 *
 * The telephone number shown to oversized parties is deployment configuration:
 * it is read from the `RESTAURANT_TELEPHONE` environment variable and no real
 * number is stored in this repository.
 */
final class BookingService
{
    /** Unambiguous reference alphabet: no O/0/I/1 (URS REQ-1.2 7.8). */
    public const REFERENCE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const REFERENCE_LENGTH = 8;

    public const MAX_REFERENCE_ATTEMPTS = 5;

    /** URS REQ-1.1 6.8: larger parties must telephone the restaurant. */
    public const MAX_PARTY_SIZE = 20;

    public const MAX_NAME_LENGTH = 80;

    public const MAX_EMAIL_LENGTH = 254;

    public const MAX_MOBILE_LENGTH = 32;

    public const MAX_SPECIAL_REQUESTS_LENGTH = 200;

    public const MAX_ALTERNATIVES = 3;

    /** Deployment supplies the restaurant telephone number; never commit one. */
    private const TELEPHONE_ENV = 'RESTAURANT_TELEPHONE';

    /** Reminder lead times in hours before the booking starts (REQ-1.2 7.7). */
    private const REMINDER_HOURS = [24, 2];

    private PDO $pdo;

    private AvailabilityService $availability;

    private Clock $clock;

    public function __construct(PDO $pdo, ?AvailabilityService $availability = null, ?Clock $clock = null)
    {
        $this->pdo = $pdo;
        $this->clock = $clock ?? new SystemClock();
        $this->availability = $availability ?? new AvailabilityService($pdo, $this->clock);
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array{booking: array<string,mixed>, created: bool}
     *
     * @throws DomainException
     */
    public function create(array $input): array
    {
        $data = $this->validateInput($input);

        // Idempotency replay: a double submit must not create a second row and
        // must not queue a second confirmation e-mail (REQ-1.2 7.9).
        if ($data['idempotency_token'] !== null) {
            $replay = $this->findIdempotentBooking($data['idempotency_token']);
            if ($replay !== null) {
                return ['booking' => $replay, 'created' => false];
            }
        }

        try {
            $this->beginWriteTransaction();

            // Re-check the token under the lock: a concurrent double submit
            // may have committed between the pre-check above and this lock.
            if ($data['idempotency_token'] !== null) {
                $replay = $this->findIdempotentBooking($data['idempotency_token']);
                if ($replay !== null) {
                    $this->commit();

                    return ['booking' => $replay, 'created' => false];
                }
            }

            $booking = $this->createWithinTransaction($data);
            $this->commit();
        } catch (Throwable $exception) {
            $this->rollBack();

            // Two concurrent requests may both miss the idempotency pre-check;
            // the UNIQUE constraint on the token is the backstop. The winner's
            // booking is then replayed instead of failing the request.
            if (
                $exception instanceof PDOException
                && $this->isUniqueViolation($exception)
                && $data['idempotency_token'] !== null
            ) {
                $replay = $this->findIdempotentBooking($data['idempotency_token']);
                if ($replay !== null) {
                    return ['booking' => $replay, 'created' => false];
                }
            }

            throw $exception;
        }

        try {
            $this->availability->invalidate($data['restaurant_id'], $data['date']);
        } catch (Throwable) {
            // A stale availability cache must never undo a confirmed booking.
        }

        return ['booking' => $booking, 'created' => true];
    }

    /**
     * Validate every field up front and report all problems at once (rule 6.1).
     *
     * @param array<string,mixed> $input
     *
     * @return array{
     *     restaurant_id:int,
     *     table_id:int,
     *     date:string,
     *     time:string,
     *     party_size:int,
     *     name:string,
     *     email:string,
     *     mobile:?string,
     *     special_requests:?string,
     *     idempotency_token:?string
     * }
     *
     * @throws DomainException
     */
    private function validateInput(array $input): array
    {
        $errors = [];

        $restaurantId = $this->positiveIntOrNull($input['restaurant_id'] ?? null);
        if ($restaurantId === null) {
            $errors['restaurant_id'] = 'A restaurant is required.';
        }

        $tableId = $this->positiveIntOrNull($input['table_id'] ?? null);
        if ($tableId === null) {
            $errors['table_id'] = 'A table is required.';
        }

        $date = is_string($input['date'] ?? null) ? trim($input['date']) : '';
        if ($date === '') {
            $errors['date'] = 'A booking date is required.';
        } elseif (!Slot::isValidDate($date)) {
            $errors['date'] = 'The booking date must be a valid date in YYYY-MM-DD format.';
        }

        $time = is_string($input['time'] ?? null) ? trim($input['time']) : '';
        if ($time === '') {
            $errors['time'] = 'A booking time is required.';
        } elseif (!Slot::isValidTime($time)) {
            $errors['time'] = 'The booking time must be a valid time in HH:MM format.';
        }

        $partySize = $this->positiveIntOrNull($input['party_size'] ?? null);
        if ($partySize === null) {
            $errors['party_size'] = 'The party size must be a whole number greater than zero.';
        }

        $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
        if ($name === '') {
            $errors['name'] = 'A guest name is required.';
        } elseif (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            $errors['name'] = 'The guest name must be at most ' . self::MAX_NAME_LENGTH . ' characters.';
        }

        $email = is_string($input['email'] ?? null) ? mb_strtolower(trim($input['email'])) : '';
        if ($email === '') {
            $errors['email'] = 'An e-mail address is required.';
        } elseif (mb_strlen($email) > self::MAX_EMAIL_LENGTH || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Invalid e-mail address.';
        }

        $mobile = null;
        if (($input['mobile'] ?? null) !== null) {
            if (!is_string($input['mobile'])) {
                $errors['mobile'] = 'The mobile number must be text.';
            } else {
                $mobile = trim($input['mobile']);
                if ($mobile === '') {
                    $mobile = null;
                } elseif (mb_strlen($mobile) > self::MAX_MOBILE_LENGTH) {
                    $errors['mobile'] = 'The mobile number must be at most ' . self::MAX_MOBILE_LENGTH . ' characters.';
                }
            }
        }

        $specialRequests = null;
        if (($input['special_requests'] ?? null) !== null) {
            if (!is_string($input['special_requests'])) {
                $errors['special_requests'] = 'Special requests must be text.';
            } else {
                $specialRequests = trim($input['special_requests']);
                if ($specialRequests === '') {
                    $specialRequests = null;
                } elseif (mb_strlen($specialRequests) > self::MAX_SPECIAL_REQUESTS_LENGTH) {
                    $errors['special_requests'] = 'Special requests must be at most '
                        . self::MAX_SPECIAL_REQUESTS_LENGTH . ' characters.';
                }
            }
        }

        $idempotencyToken = null;
        if (($input['idempotency_token'] ?? null) !== null) {
            if (!is_string($input['idempotency_token'])) {
                $errors['idempotency_token'] = 'The idempotency token must be text.';
            } else {
                $candidate = trim($input['idempotency_token']);
                if (preg_match('/^[A-Za-z0-9_-]{8,64}$/', $candidate) !== 1) {
                    $errors['idempotency_token'] = 'The idempotency token must be 8 to 64 letters, digits, '
                        . 'hyphens or underscores.';
                } else {
                    $idempotencyToken = $candidate;
                }
            }
        }

        if ($errors !== []) {
            throw DomainException::validation($errors);
        }

        // The type checks above passed, so these casts cannot lose information.
        $restaurantId = (int) $restaurantId;
        $tableId = (int) $tableId;
        $partySize = (int) $partySize;

        if ($partySize > self::MAX_PARTY_SIZE) {
            throw new DomainException(
                'PARTY_TOO_LARGE',
                $this->withTelephoneHint(
                    'Please call the restaurant to book a table for more than '
                        . self::MAX_PARTY_SIZE . ' guests.'
                ),
                $this->partyTooLargeDetails(['max_party_size' => self::MAX_PARTY_SIZE]),
                422
            );
        }

        return [
            'restaurant_id' => $restaurantId,
            'table_id' => $tableId,
            'date' => $date,
            'time' => $time,
            'party_size' => $partySize,
            'name' => $name,
            'email' => $email,
            'mobile' => $mobile,
            'special_requests' => $specialRequests,
            'idempotency_token' => $idempotencyToken,
        ];
    }

    /**
     * All rule checks that need the write lock, then the writes themselves.
     *
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     *
     * @throws DomainException
     */
    private function createWithinTransaction(array $data): array
    {
        $driver = Database::driver($this->pdo);

        $table = $this->fetchTableForUpdate($data['table_id'], $driver);
        if ($table === false || (int) $table['restaurant_id'] !== $data['restaurant_id']) {
            throw DomainException::notFound('The selected table does not exist in this restaurant.');
        }

        $restaurant = $this->fetchRestaurant($data['restaurant_id']);
        if ($restaurant === false) {
            throw DomainException::notFound('Restaurant not found.');
        }
        $timezone = new DateTimeZone((string) $restaurant['timezone']);

        if ((string) $table['status'] !== 'FREE') {
            throw new DomainException(
                'TABLE_UNAVAILABLE',
                'The selected table is not available for booking.',
                ['table_id' => $data['table_id'], 'status' => (string) $table['status']],
                409
            );
        }

        if ($data['party_size'] > (int) $table['seats']) {
            throw new DomainException(
                'PARTY_TOO_LARGE',
                $this->withTelephoneHint(
                    'The selected table does not seat this many guests; please call the restaurant to book a larger table.'
                ),
                $this->partyTooLargeDetails([
                    'table_id' => $data['table_id'],
                    'party_size' => $data['party_size'],
                    'max_party_size' => (int) $table['seats'],
                ]),
                422
            );
        }

        $now = $this->clock->now()->setTimezone($timezone);
        $today = $now->format('Y-m-d');
        $lastBookableDate = $now->modify('+' . Slot::MAX_DAYS_AHEAD . ' days')->format('Y-m-d');
        if ($data['date'] < $today || $data['date'] > $lastBookableDate) {
            throw new DomainException(
                'DATE_OUT_OF_RANGE',
                sprintf('Bookings can be made between %s and %s.', $today, $lastBookableDate),
                ['min' => $today, 'max' => $lastBookableDate],
                422
            );
        }

        $period = $this->findServicePeriod($data['restaurant_id'], $data['time']);
        if ($period === null) {
            throw new DomainException(
                'NO_SERVICE_PERIOD',
                'The restaurant does not serve at the selected time.',
                ['periods' => $this->servicePeriods($data['restaurant_id'])],
                422
            );
        }

        // The whole sitting (dining time + turnover buffer) must end before the
        // service period closes, exactly like AvailabilityService::search().
        $slotMinutes = (int) $period['avg_dining_minutes'] + Slot::TURNOVER_BUFFER_MINUTES;
        $periodEnd = (string) $period['end_time'];
        if (Slot::minutes($data['time']) + $slotMinutes > Slot::minutes($periodEnd)) {
            $latestStart = Slot::addMinutes($periodEnd, -$slotMinutes);
            throw new DomainException(
                'NO_SERVICE_PERIOD',
                'The restaurant closes before this sitting would end. The latest bookable start is '
                    . $latestStart . '.',
                ['requested_time' => $data['time'], 'latest_start' => $latestStart],
                422
            );
        }

        $start = Slot::at($data['date'], $data['time'], $timezone);
        if ($start <= $now) {
            throw new DomainException(
                'SLOT_IN_PAST',
                'The selected time is in the past.',
                ['date' => $data['date'], 'time' => $data['time'], 'now' => Slot::iso8601($now)],
                422
            );
        }

        $endTime = Slot::addMinutes($data['time'], $slotMinutes);

        if (!$this->availability->isTableFree($data['table_id'], $data['date'], $data['time'], $endTime)) {
            throw DomainException::tableTaken($data['table_id'], $this->alternativeTables($data));
        }

        $nowIso = Slot::iso8601($now);
        $cutoffAt = Slot::iso8601($start->modify('-' . Slot::DEFAULT_CUTOFF_MINUTES . ' minutes'));

        $reference = $this->insertBooking($data, $endTime, $cutoffAt, $nowIso);
        $bookingId = (int) $this->pdo->lastInsertId();

        $row = [
            'id' => $bookingId,
            'reference' => $reference,
            'restaurant_id' => $data['restaurant_id'],
            'table_id' => $data['table_id'],
            'booking_date' => $data['date'],
            'start_time' => $data['time'],
            'end_time' => $endTime,
            'party_size' => $data['party_size'],
            'status' => 'CONFIRMED',
            'guest_name' => $data['name'],
            'guest_email' => $data['email'],
            'guest_mobile' => $data['mobile'],
            'special_requests' => $data['special_requests'],
            'channel' => 'online',
            'cutoff_at' => $cutoffAt,
            'created_at' => $nowIso,
        ];

        $this->insertEvent($bookingId, $row, $nowIso);

        if ($data['idempotency_token'] !== null) {
            $this->insertIdempotencyKey($data['idempotency_token'], $bookingId, $nowIso);
        }

        $this->insertNotification($bookingId, $nowIso);

        return $this->buildBookingPayload($row, $table, $timezone);
    }

    /**
     * Insert the booking row, retrying when the random reference collides.
     *
     * @param array<string,mixed> $data
     */
    private function insertBooking(array $data, string $endTime, string $cutoffAt, string $nowIso): string
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO bookings
                (reference, restaurant_id, table_id, booking_date, start_time, end_time, party_size,
                 status, guest_name, guest_email, guest_mobile, special_requests, channel,
                 cutoff_at, idempotency_token, risk_score, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        for ($attempt = 1; $attempt <= self::MAX_REFERENCE_ATTEMPTS; $attempt++) {
            $reference = $this->generateReference();

            try {
                $statement->execute([
                    $reference,
                    $data['restaurant_id'],
                    $data['table_id'],
                    $data['date'],
                    $data['time'],
                    $endTime,
                    $data['party_size'],
                    'CONFIRMED',
                    $data['name'],
                    $data['email'],
                    $data['mobile'],
                    $data['special_requests'],
                    'online',
                    $cutoffAt,
                    $data['idempotency_token'],
                    null,
                    $nowIso,
                    $nowIso,
                ]);

                return $reference;
            } catch (PDOException $exception) {
                if (!$this->isReferenceCollision($exception)) {
                    throw $exception;
                }
                // Extremely unlikely: the 8-char reference already exists.
                // Loop and draw a new one (at most MAX_REFERENCE_ATTEMPTS times).
            }
        }

        throw new DomainException(
            'CONFLICT',
            'A unique booking reference could not be allocated. Please try again.',
            [],
            409
        );
    }

    /**
     * @param array<string,mixed> $row
     */
    private function insertEvent(int $bookingId, array $row, string $nowIso): void
    {
        $payload = json_encode(
            [
                'reference' => $row['reference'],
                'restaurant_id' => $row['restaurant_id'],
                'table_id' => $row['table_id'],
                'date' => $row['booking_date'],
                'start' => $row['start_time'],
                'end' => $row['end_time'],
                'party_size' => $row['party_size'],
                'status' => $row['status'],
                'channel' => $row['channel'],
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        $statement = $this->pdo->prepare(
            'INSERT INTO booking_events (booking_id, event_type, actor, payload, occurred_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([$bookingId, 'created', 'diner', $payload, $nowIso]);
    }

    private function insertIdempotencyKey(string $token, int $bookingId, string $nowIso): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO idempotency_keys (token, booking_id, created_at) VALUES (?, ?, ?)'
        );
        $statement->execute([$token, $bookingId, $nowIso]);
    }

    /** Simulated confirmation e-mail (W8 replaces this with a real outbox). */
    private function insertNotification(int $bookingId, string $nowIso): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO notification_log (booking_id, channel, template, status, attempts, scheduled_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([$bookingId, 'email', 'booking_confirmation', 'QUEUED', 0, $nowIso]);
    }

    /**
     * @param array<string,mixed> $data
     *
     * @return list<array{id:int,code:string,seats:int,area:string}>
     */
    private function alternativeTables(array $data): array
    {
        try {
            $result = $this->availability->search(
                $data['restaurant_id'],
                $data['date'],
                $data['time'],
                $data['party_size']
            );
        } catch (Throwable) {
            return [];
        }

        $tables = $result['tables'] ?? [];
        if (!is_array($tables)) {
            return [];
        }

        $alternatives = [];
        foreach ($tables as $table) {
            if (!is_array($table)) {
                continue;
            }
            if ((int) ($table['id'] ?? 0) === $data['table_id']) {
                continue;
            }
            $alternatives[] = [
                'id' => (int) ($table['id'] ?? 0),
                'code' => (string) ($table['code'] ?? ''),
                'seats' => (int) ($table['seats'] ?? 0),
                'area' => (string) ($table['area'] ?? ''),
            ];
            if (count($alternatives) >= self::MAX_ALTERNATIVES) {
                break;
            }
        }

        return $alternatives;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findIdempotentBooking(string $token): ?array
    {
        $statement = $this->pdo->prepare('SELECT booking_id FROM idempotency_keys WHERE token = ?');
        $statement->execute([$token]);
        $bookingId = $statement->fetchColumn();

        if ($bookingId === false) {
            return null;
        }

        $booking = $this->fetchBooking((int) $bookingId);
        if ($booking === false) {
            return null;
        }

        $table = $this->fetchTable((int) $booking['table_id']);
        if ($table === false) {
            return null;
        }

        $restaurant = $this->fetchRestaurant((int) $booking['restaurant_id']);
        if ($restaurant === false) {
            return null;
        }

        return $this->buildBookingPayload($booking, $table, new DateTimeZone((string) $restaurant['timezone']));
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $tableRow
     *
     * @return array<string,mixed>
     */
    private function buildBookingPayload(array $row, array $tableRow, DateTimeZone $timezone): array
    {
        $start = Slot::at((string) $row['booking_date'], (string) $row['start_time'], $timezone);

        $reminders = [];
        foreach (self::REMINDER_HOURS as $hours) {
            $reminders[] = Slot::iso8601($start->modify('-' . $hours . ' hours'));
        }

        return [
            'id' => (int) $row['id'],
            'reference' => (string) $row['reference'],
            'status' => (string) $row['status'],
            'date' => (string) $row['booking_date'],
            'start' => (string) $row['start_time'],
            'end' => (string) $row['end_time'],
            'party_size' => (int) $row['party_size'],
            'table' => [
                'id' => (int) $tableRow['id'],
                'code' => (string) $tableRow['code'],
                'seats' => (int) $tableRow['seats'],
                'area' => (string) $tableRow['area'],
            ],
            'guest' => [
                'name' => (string) $row['guest_name'],
                'email' => (string) $row['guest_email'],
                'mobile' => $row['guest_mobile'] === null ? null : (string) $row['guest_mobile'],
            ],
            'special_requests' => $row['special_requests'] === null ? null : (string) $row['special_requests'],
            'cutoff_at' => Slot::iso8601($start->modify('-' . Slot::DEFAULT_CUTOFF_MINUTES . ' minutes')),
            'reminders' => $reminders,
            'channel' => (string) $row['channel'],
            'created_at' => $this->iso8601FromDatabase((string) $row['created_at'], $timezone),
        ];
    }

    /**
     * Turn a stored timestamp into ISO 8601. SQLite keeps the offset we write;
     * MySQL DATETIME drops it, so it is re-attached from the restaurant zone.
     */
    private function iso8601FromDatabase(string $value, DateTimeZone $timezone): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $value) === 1) {
            return $value;
        }

        try {
            return Slot::iso8601(new DateTimeImmutable($value, $timezone));
        } catch (\Exception) {
            return $value;
        }
    }

    private function generateReference(): string
    {
        $max = strlen(self::REFERENCE_ALPHABET) - 1;
        $reference = '';
        for ($index = 0; $index < self::REFERENCE_LENGTH; $index++) {
            $reference .= self::REFERENCE_ALPHABET[random_int(0, $max)];
        }

        return $reference;
    }

    /**
     * The restaurant telephone number is deployment configuration, never
     * repository data: an unset/empty `RESTAURANT_TELEPHONE` means the hint
     * simply shows no number.
     */
    private function telephone(): string
    {
        $value = getenv(self::TELEPHONE_ENV);

        return is_string($value) ? trim($value) : '';
    }

    private function withTelephoneHint(string $message): string
    {
        $telephone = $this->telephone();

        return $telephone === '' ? $message : $message . ' Telephone: ' . $telephone;
    }

    /**
     * @param array<string,mixed> $details
     *
     * @return array<string,mixed>
     */
    private function partyTooLargeDetails(array $details): array
    {
        $telephone = $this->telephone();
        if ($telephone !== '') {
            $details['telephone'] = $telephone;
        }

        return $details;
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

    private function findServicePeriod(int $restaurantId, string $time): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM service_periods
             WHERE restaurant_id = ? AND start_time <= ? AND end_time > ?
             ORDER BY id
             LIMIT 1'
        );
        $statement->execute([$restaurantId, $time, $time]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return list<array{name:string,start:string,end:string}> */
    private function servicePeriods(int $restaurantId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT name, start_time, end_time FROM service_periods WHERE restaurant_id = ? ORDER BY id'
        );
        $statement->execute([$restaurantId]);

        $periods = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $periods[] = [
                'name' => (string) $row['name'],
                'start' => (string) $row['start_time'],
                'end' => (string) $row['end_time'],
            ];
        }

        return $periods;
    }

    /** @return array<string,mixed>|false */
    private function fetchTableForUpdate(int $tableId, string $driver): array|false
    {
        $sql = 'SELECT id, restaurant_id, code, seats, area, status FROM tables WHERE id = ?';
        if ($driver !== 'sqlite') {
            $sql .= ' FOR UPDATE';
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute([$tableId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : false;
    }

    /** @return array<string,mixed>|false */
    private function fetchTable(int $tableId): array|false
    {
        $statement = $this->pdo->prepare('SELECT * FROM tables WHERE id = ?');
        $statement->execute([$tableId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : false;
    }

    /** @return array<string,mixed>|false */
    private function fetchRestaurant(int $restaurantId): array|false
    {
        $statement = $this->pdo->prepare('SELECT * FROM restaurants WHERE id = ?');
        $statement->execute([$restaurantId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : false;
    }

    /** @return array<string,mixed>|false */
    private function fetchBooking(int $bookingId): array|false
    {
        $statement = $this->pdo->prepare('SELECT * FROM bookings WHERE id = ?');
        $statement->execute([$bookingId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : false;
    }

    private function beginWriteTransaction(): void
    {
        if (Database::driver($this->pdo) === 'sqlite') {
            // DEFERRED would be upgraded only on the first write: two readers
            // could both pass isTableFree() before either writes.
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
            // No transaction was open (e.g. BEGIN IMMEDIATE itself failed).
        }
    }

    private function isUniqueViolation(PDOException $exception): bool
    {
        $sqlState = (string) $exception->getCode();
        if ($sqlState === '23000' || $sqlState === '23505') {
            return true;
        }

        $message = $exception->getMessage();

        return stripos($message, 'unique constraint') !== false || stripos($message, 'duplicate entry') !== false;
    }

    private function isReferenceCollision(PDOException $exception): bool
    {
        return $this->isUniqueViolation($exception)
            && stripos($exception->getMessage(), 'reference') !== false;
    }
}

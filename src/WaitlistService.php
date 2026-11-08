<?php

declare(strict_types=1);

namespace DineReserve;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * Waitlist and automatic table release for URS REQ-1.5:
 *
 *  - diners join a per-restaurant/per-day queue (strict arrival order),
 *  - when a confirmed booking is cancelled the freed slot is offered to the
 *    first waiter whose party fits the table,
 *  - the offer holds the table for five minutes; accepting converts it into a
 *    real booking, declining or expiring passes the slot to the next waiter.
 */
final class WaitlistService
{
    public const STATUS_WAITING = 'WAITING';
    public const STATUS_OFFERED = 'OFFERED';
    public const STATUS_CONVERTED = 'CONVERTED';
    public const STATUS_DECLINED = 'DECLINED';
    public const STATUS_EXPIRED = 'EXPIRED';
    public const STATUS_PASSED_OVER = 'PASSED_OVER';

    /** The offer must be accepted within this many minutes (URS REQ-1.5 §10). */
    public const OFFER_TTL_MINUTES = 5;

    public const MAX_PARTY_SIZE = 20;
    public const MAX_NAME_LENGTH = 80;
    public const MAX_CONTACT_LENGTH = 254;

    private PDO $pdo;

    private AvailabilityService $availability;

    private Clock $clock;

    private ?Mailer $mailer;

    private ?BookingService $bookings;

    public function __construct(
        PDO $pdo,
        ?AvailabilityService $availability = null,
        ?Clock $clock = null,
        ?Mailer $mailer = null,
        ?BookingService $bookings = null
    ) {
        $this->pdo = $pdo;
        $this->availability = $availability ?? new AvailabilityService($pdo, $clock);
        $this->clock = $clock ?? new SystemClock();
        $this->mailer = $mailer;
        $this->bookings = $bookings;
    }

    /**
     * Join the queue for a date / service period.
     *
     * @param array<string,mixed> $input restaurant_id, date, time, party_size, name, email
     * @return array{entry:array<string,mixed>,position:int,created:bool}
     */
    public function join(array $input): array
    {
        $data = $this->validateJoin($input);
        $period = $this->findServicePeriod($data['restaurant_id'], $data['time']);
        if ($period === null) {
            throw new DomainException(
                'NO_SERVICE_PERIOD',
                'The restaurant does not serve at the requested time.',
                ['periods' => $this->servicePeriods($data['restaurant_id'])],
                422
            );
        }

        $existing = $this->findWaitingEntry(
            $data['restaurant_id'],
            $data['date'],
            $data['email'],
            $data['party_size']
        );
        if ($existing !== false) {
            return [
                'entry' => $this->payload($existing, $this->position((int) $existing['id'])),
                'position' => $this->position((int) $existing['id']),
                'created' => false,
            ];
        }

        $nowIso = Slot::iso8601($this->clock->now());
        $statement = $this->pdo->prepare(
            'INSERT INTO waitlist_entries
                (restaurant_id, service_period_id, entry_date, party_size, guest_name, guest_contact, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $data['restaurant_id'],
            (int) $period['id'],
            $data['date'],
            $data['party_size'],
            $data['name'],
            $data['email'],
            self::STATUS_WAITING,
            $this->storageDateTime($nowIso),
        ]);

        $entryId = (int) $this->pdo->lastInsertId();
        $this->audit('waitlist.join', $entryId, [
            'restaurant_id' => $data['restaurant_id'],
            'date' => $data['date'],
            'party_size' => $data['party_size'],
        ]);

        $entry = $this->findEntry($entryId);
        if ($entry === false) {
            throw new DomainException('WAITLIST_ERROR', 'The waitlist entry could not be stored.', [], 500);
        }

        return [
            'entry' => $this->payload($entry, $this->position($entryId)),
            'position' => $this->position($entryId),
            'created' => true,
        ];
    }

    /** Queue position: 1 is the next diner to be served. */
    public function position(int $entryId): int
    {
        $entry = $this->findEntry($entryId);
        if ($entry === false) {
            throw DomainException::notFound('Waitlist entry not found.');
        }

        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM waitlist_entries
              WHERE restaurant_id = ? AND entry_date = ? AND status = ? AND id <= ?'
        );
        $statement->execute([
            (int) $entry['restaurant_id'],
            (string) $entry['entry_date'],
            self::STATUS_WAITING,
            $entryId,
        ]);

        return max(1, (int) $statement->fetchColumn());
    }

    /** @return array<string,mixed> */
    public function getEntry(int $entryId): array
    {
        $entry = $this->findEntry($entryId);
        if ($entry === false) {
            throw DomainException::notFound('Waitlist entry not found.');
        }

        return $this->payload($entry, $this->position($entryId));
    }

    /**
     * Offer a freed slot to the first matching waiter (REQ-1.5 10.4).
     *
     * Called when a booking is cancelled (and later by the floor-plan release
     * in W10). Returns the offer payload, or null when nobody fits.
     *
     * @return array<string,mixed>|null
     */
    public function offerNextForReleasedSlot(
        int $restaurantId,
        string $date,
        string $time,
        int $tableId
    ): ?array {
        $table = $this->findTable($tableId);
        if ($table === false || (int) $table['restaurant_id'] !== $restaurantId) {
            return null;
        }
        if (strtoupper((string) $table['status']) !== 'FREE') {
            return null;
        }
        if (!$this->availability->isTableFree($tableId, $date, $time, $this->slotEnd($restaurantId, $time))) {
            return null;
        }

        $period = $this->findServicePeriod($restaurantId, $time);
        if ($period === null) {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT * FROM waitlist_entries
              WHERE restaurant_id = ? AND entry_date = ? AND status = ? AND party_size <= ?
                AND service_period_id = ?
              ORDER BY created_at, id
              LIMIT 1'
        );
        $statement->execute([
            $restaurantId,
            $date,
            self::STATUS_WAITING,
            (int) $table['seats'],
            (int) $period['id'],
        ]);
        $candidate = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($candidate)) {
            return null;
        }

        $deadline = $this->clock->now()->modify('+' . self::OFFER_TTL_MINUTES . ' minutes');
        $nowIso = Slot::iso8601($this->clock->now());
        $update = $this->pdo->prepare(
            'UPDATE waitlist_entries
                SET status = ?, offer_expires_at = ?, offer_table_id = ?, offer_time = ?
              WHERE id = ? AND status = ?'
        );
        $update->execute([
            self::STATUS_OFFERED,
            $this->storageDateTime(Slot::iso8601($deadline)),
            $tableId,
            $time,
            (int) $candidate['id'],
            self::STATUS_WAITING,
        ]);
        if ($update->rowCount() !== 1) {
            return null;
        }

        $this->audit('waitlist.offer', (int) $candidate['id'], [
            'restaurant_id' => $restaurantId,
            'date' => $date,
            'time' => $time,
            'table_id' => $tableId,
            'offer_expires_at' => Slot::iso8601($deadline),
        ]);
        $this->sendOfferNotice($candidate, $table, $date, $time, $deadline, $nowIso);

        $entry = $this->findEntry((int) $candidate['id']);
        if ($entry === false) {
            return null;
        }

        return $this->payload($entry, $this->position((int) $candidate['id']));
    }

    /**
     * Accept an offer: claim the offer row atomically, then create the booking
     * through the normal booking rules (so availability is re-checked).
     *
     * @param array<string,mixed> $input email of the diner
     * @return array{entry:array<string,mixed>,booking:array<string,mixed>}
     */
    public function accept(int $entryId, array $input): array
    {
        $entry = $this->findEntry($entryId);
        if ($entry === false) {
            throw DomainException::notFound('Waitlist entry not found.');
        }
        $this->assertContact($entry, $input);

        if ((string) $entry['status'] === self::STATUS_EXPIRED) {
            throw $this->offerExpired();
        }
        if ((string) $entry['status'] !== self::STATUS_OFFERED) {
            throw new DomainException(
                'WAITLIST_NOT_OFFERED',
                'This waitlist entry does not have a live table offer.',
                ['status' => (string) $entry['status']],
                409
            );
        }

        $now = $this->clock->now();
        $expires = $this->offerDeadline($entry);
        if ($expires === null || $now > $expires) {
            $this->markStatus($entryId, self::STATUS_EXPIRED);
            throw $this->offerExpired();
        }

        $claim = $this->pdo->prepare(
            'UPDATE waitlist_entries SET status = ? WHERE id = ? AND status = ?'
        );
        $claim->execute([self::STATUS_CONVERTED, $entryId, self::STATUS_OFFERED]);
        if ($claim->rowCount() !== 1) {
            throw new DomainException(
                'WAITLIST_NOT_OFFERED',
                'This waitlist entry does not have a live table offer.',
                ['status' => (string) $entry['status']],
                409
            );
        }

        if ($this->bookings === null) {
            throw new DomainException('WAITLIST_ERROR', 'The booking service is not wired.', [], 500);
        }

        try {
            $restaurant = $this->findRestaurant((int) $entry['restaurant_id']);
            $time = (string) ($entry['offer_time'] ?? '');
            $result = $this->bookings->create([
                'restaurant_id' => (int) $entry['restaurant_id'],
                'table_id' => (int) $entry['offer_table_id'],
                'date' => (string) $entry['entry_date'],
                'time' => $time,
                'party_size' => (int) $entry['party_size'],
                'name' => (string) $entry['guest_name'],
                'email' => (string) $entry['guest_contact'],
                'channel' => 'waitlist',
                'special_requests' => 'Converted from the waitlist after a freed table offer.',
                'idempotency_token' => 'waitlist-' . $entryId,
            ]);
        } catch (Throwable $exception) {
            // The booking failed (e.g. the table was taken meanwhile): give the
            // offer back so the diner can retry or the slot passes to the next waiter.
            $this->pdo->prepare('UPDATE waitlist_entries SET status = ? WHERE id = ? AND status = ?')
                ->execute([self::STATUS_OFFERED, $entryId, self::STATUS_CONVERTED]);

            throw $exception;
        }

        $this->audit('waitlist.converted', $entryId, [
            'reference' => $result['booking']['reference'] ?? null,
            'restaurant' => $restaurant === false ? null : (string) $restaurant['name'],
        ]);

        $fresh = $this->findEntry($entryId);

        return [
            'entry' => $this->payload($fresh === false ? $entry : $fresh, $this->position($entryId)),
            'booking' => $result['booking'],
        ];
    }

    /**
     * Decline an offer: the slot is immediately offered to the next waiter.
     *
     * @param array<string,mixed> $input email of the diner
     * @return array{entry:array<string,mixed>,offered:array<string,mixed>|null}
     */
    public function decline(int $entryId, array $input): array
    {
        $entry = $this->findEntry($entryId);
        if ($entry === false) {
            throw DomainException::notFound('Waitlist entry not found.');
        }
        $this->assertContact($entry, $input);

        if ((string) $entry['status'] !== self::STATUS_OFFERED) {
            throw new DomainException(
                'WAITLIST_NOT_OFFERED',
                'This waitlist entry does not have a live table offer.',
                ['status' => (string) $entry['status']],
                409
            );
        }

        $this->markStatus($entryId, self::STATUS_DECLINED);
        $this->audit('waitlist.declined', $entryId, []);
        $next = $this->reoffer($entry);

        $fresh = $this->findEntry($entryId);

        return [
            'entry' => $this->payload($fresh === false ? $entry : $fresh, 0),
            'offered' => $next,
        ];
    }

    /**
     * Expire every offer whose five minutes are over and pass the slot on.
     *
     * @return array{expired:int,offered:int}
     */
    public function expireOffers(): array
    {
        $nowStorage = $this->storageDateTime(Slot::iso8601($this->clock->now()));
        $statement = $this->pdo->prepare(
            'SELECT * FROM waitlist_entries
              WHERE status = ? AND offer_expires_at IS NOT NULL AND offer_expires_at <= ?
              ORDER BY offer_expires_at, id'
        );
        $statement->execute([self::STATUS_OFFERED, $nowStorage]);

        $expired = 0;
        $offered = 0;
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $entry) {
            $this->markStatus((int) $entry['id'], self::STATUS_EXPIRED);
            $this->audit('waitlist.offer_expired', (int) $entry['id'], []);
            $expired++;

            if ($this->reoffer($entry) !== null) {
                $offered++;
            }
        }

        return ['expired' => $expired, 'offered' => $offered];
    }

    /** Offer the entry's held slot to the next waiter, if the table is still free. */
    private function reoffer(array $entry): ?array
    {
        $tableId = (int) ($entry['offer_table_id'] ?? 0);
        $time = (string) ($entry['offer_time'] ?? '');
        if ($tableId < 1 || $time === '') {
            return null;
        }

        return $this->offerNextForReleasedSlot(
            (int) $entry['restaurant_id'],
            (string) $entry['entry_date'],
            $time,
            $tableId
        );
    }

    /** @param array<string,mixed> $entry */
    private function sendOfferNotice(array $entry, array $table, string $date, string $time, DateTimeImmutable $deadline, string $nowIso): void
    {
        if ($this->mailer === null) {
            return;
        }

        $base = getenv('WAITLIST_BASE_URL');
        if (!is_string($base) || trim($base) === '') {
            $base = 'http://127.0.0.1:8080';
        }
        $body = implode("\n", [
            'Dear ' . (string) $entry['guest_name'] . ',',
            '',
            'A table has just become available.',
            '',
            'Date      : ' . $date,
            'Time      : ' . $time,
            'Table     : ' . (string) $table['code'] . ' (' . (string) $table['area'] . ')',
            'Party     : ' . (int) $entry['party_size'],
            '',
            'This offer is held for ' . self::OFFER_TTL_MINUTES . ' minutes, until '
                . $deadline->format('H:i') . '.',
            'Accept it here: ' . rtrim($base, '/') . '/api/v1/waitlist/' . (int) $entry['id'] . '/accept',
            '',
            'If you no longer need the table, please decline so the next guest can have it.',
        ]);

        try {
            $this->mailer->send(
                (string) $entry['guest_contact'],
                'A table is available for ' . self::OFFER_TTL_MINUTES . ' minutes',
                $body
            );
        } catch (Throwable) {
            // The offer still stands in the system; the e-mail is best effort.
        }
    }

    // ------------------------------------------------------------ helpers

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function validateJoin(array $input): array
    {
        $errors = [];

        $restaurantId = $this->positiveIntOrNull($input['restaurant_id'] ?? null);
        if ($restaurantId === null) {
            $errors['restaurant_id'] = 'A restaurant is required.';
        }

        $date = is_string($input['date'] ?? null) ? trim($input['date']) : '';
        if ($date === '' || !Slot::isValidDate($date)) {
            $errors['date'] = 'The date must be a real date in YYYY-MM-DD format.';
        }

        $time = is_string($input['time'] ?? null) ? trim($input['time']) : '';
        if ($time === '' || !Slot::isValidTime($time)) {
            $errors['time'] = 'The time must be in HH:MM format.';
        }

        $partySize = $this->positiveIntOrNull($input['party_size'] ?? null);
        if ($partySize === null) {
            $errors['party_size'] = 'The party size must be a whole number greater than zero.';
        } elseif ($partySize > self::MAX_PARTY_SIZE) {
            $errors['party_size'] = 'Please call the restaurant for more than ' . self::MAX_PARTY_SIZE . ' guests.';
        }

        $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            $errors['name'] = 'A guest name of at most ' . self::MAX_NAME_LENGTH . ' characters is required.';
        }

        $email = is_string($input['email'] ?? null) ? mb_strtolower(trim($input['email'])) : '';
        if ($email === '' || mb_strlen($email) > self::MAX_CONTACT_LENGTH || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'A valid e-mail address is required.';
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
        $today = $now->format('Y-m-d');
        $lastDate = $now->modify('+' . Slot::MAX_DAYS_AHEAD . ' days')->format('Y-m-d');
        if ($date < $today || $date > $lastDate) {
            throw new DomainException(
                'DATE_OUT_OF_RANGE',
                sprintf('Waitlist entries can be made between %s and %s.', $today, $lastDate),
                ['min' => $today, 'max' => $lastDate],
                422
            );
        }

        return [
            'restaurant_id' => (int) $restaurantId,
            'date' => $date,
            'time' => $time,
            'party_size' => (int) $partySize,
            'name' => $name,
            'email' => $email,
        ];
    }

    /** @param array<string,mixed> $entry @param array<string,mixed> $input */
    private function assertContact(array $entry, array $input): void
    {
        $email = is_string($input['email'] ?? null) ? mb_strtolower(trim($input['email'])) : '';
        if ($email === '' || !hash_equals((string) $entry['guest_contact'], $email)) {
            throw DomainException::notFound('Waitlist entry not found.');
        }
    }

    private function offerExpired(): DomainException
    {
        return new DomainException(
            'OFFER_EXPIRED',
            'The five-minute table offer has expired; the table was passed to the next guest.',
            ['ttl_minutes' => self::OFFER_TTL_MINUTES],
            410
        );
    }

    private function markStatus(int $entryId, string $status): void
    {
        $this->pdo->prepare('UPDATE waitlist_entries SET status = ? WHERE id = ?')
            ->execute([$status, $entryId]);
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

    /** @param array<string,mixed> $entry */
    private function payload(array $entry, int $position): array
    {
        $restaurant = $this->findRestaurant((int) $entry['restaurant_id']);
        $timezone = new DateTimeZone($restaurant === false ? 'Asia/Macau' : (string) $restaurant['timezone']);
        $table = isset($entry['offer_table_id']) ? $this->findTable((int) $entry['offer_table_id']) : false;

        return [
            'id' => (int) $entry['id'],
            'status' => (string) $entry['status'],
            'position' => $position,
            'date' => (string) $entry['entry_date'],
            'party_size' => (int) $entry['party_size'],
            'name' => (string) $entry['guest_name'],
            'service_period_id' => (int) $entry['service_period_id'],
            'offer_time' => $entry['offer_time'] === null ? null : (string) $entry['offer_time'],
            'offer_expires_at' => $this->isoFromDatabase($entry['offer_expires_at'] ?? null, $timezone),
            'table' => $table === false ? null : [
                'id' => (int) $table['id'],
                'code' => (string) $table['code'],
                'seats' => (int) $table['seats'],
                'area' => (string) $table['area'],
            ],
            'created_at' => (string) $this->isoFromDatabase($entry['created_at'], $timezone),
        ];
    }

    private function isoFromDatabase(?string $value, DateTimeZone $timezone): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $value) === 1) {
            return $value;
        }
        try {
            return Slot::iso8601((new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone($timezone));
        } catch (Throwable) {
            return $value;
        }
    }

    /** @param array<string,mixed> $entry */
    private function offerDeadline(array $entry): ?DateTimeImmutable
    {
        $raw = $entry['offer_expires_at'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($raw);
        } catch (Throwable) {
            return null;
        }
    }

    private function slotEnd(int $restaurantId, string $time): string
    {
        $period = $this->findServicePeriod($restaurantId, $time);
        $minutes = $period === null ? 90 : (int) $period['avg_dining_minutes'];

        return Slot::addMinutes($time, $minutes + Slot::TURNOVER_BUFFER_MINUTES);
    }

    /** @return array<string,mixed>|false */
    private function findEntry(int $entryId): array|false
    {
        $statement = $this->pdo->prepare('SELECT * FROM waitlist_entries WHERE id = ?');
        $statement->execute([$entryId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : false;
    }

    /** @return array<string,mixed>|false */
    private function findWaitingEntry(int $restaurantId, string $date, string $email, int $partySize): array|false
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM waitlist_entries
              WHERE restaurant_id = ? AND entry_date = ? AND guest_contact = ? AND party_size = ? AND status = ?
              LIMIT 1'
        );
        $statement->execute([$restaurantId, $date, $email, $partySize, self::STATUS_WAITING]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : false;
    }

    /** @return array<string,mixed>|null */
    private function findServicePeriod(int $restaurantId, string $time): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM service_periods
              WHERE restaurant_id = ? AND start_time <= ? AND end_time > ?
              ORDER BY id LIMIT 1'
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

    /** @param array<string,mixed> $payload */
    private function audit(string $action, int $entryId, array $payload): void
    {
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO audit_log (actor, action, entity, entity_id, payload, occurred_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([
                'system',
                $action,
                'waitlist_entry',
                (string) $entryId,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                $this->storageDateTime(Slot::iso8601($this->clock->now())),
            ]);
        } catch (Throwable) {
            // Audit is best effort; never fail the domain action because of it.
        }
    }

    private function storageDateTime(string $iso): string
    {
        return Database::dateTimeFor($this->pdo, $iso);
    }
}

<?php

declare(strict_types=1);

namespace DineReserve;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * REQ-1.1 — search restaurants and table availability.
 *
 * The service answers one question: which tables can seat a party of N guests
 * at a given date/time, using only live data and never offering the same table
 * for two overlapping slots (URS REQ-1.1 6.1–6.9).
 *
 * All time arithmetic goes through {@see Slot}, and "today" always comes from
 * the injected {@see Clock} so the date window is deterministic under test.
 */
final class AvailabilityService
{
    /** URS REQ-1.1 6.8: larger parties must phone the restaurant. */
    public const MAX_PARTY_SIZE = 20;

    /**
     * Hint returned with PARTY_TOO_LARGE. The demo schema has no phone column,
     * so this is the wording shown to the diner instead of a made-up number.
     */
    public const TELEPHONE_HINT = 'Please call the restaurant to book a table for more than '
        . self::MAX_PARTY_SIZE . ' guests.';

    /** Bookings in these states occupy a table; every other status is free. */
    private const OCCUPYING_BOOKING_STATUSES = ['CONFIRMED', 'SEATED'];

    private PDO $pdo;

    private Clock $clock;

    private int $cacheTtlSeconds;

    /** @var array<string, array{expires_at:int, result:array<string,mixed>}> */
    private array $cache = [];

    public function __construct(PDO $pdo, ?Clock $clock = null, int $cacheTtlSeconds = 30)
    {
        $this->pdo = $pdo;
        $this->clock = $clock ?? new SystemClock();
        $this->cacheTtlSeconds = $cacheTtlSeconds;
    }

    /**
     * Search the bookable tables of a restaurant for one date/time/party.
     *
     * @param array{area?:string, service_period?:string} $filters
     * @return array<string,mixed> The contract shape documented in docs/02-architecture.md §5.
     * @throws DomainException When the restaurant/date/time/party cannot be served.
     */
    public function search(int $restaurantId, string $date, string $time, int $partySize, array $filters = []): array
    {
        $restaurant = $this->restaurant($restaurantId);
        $timezone = new DateTimeZone($restaurant['timezone']);
        $now = $this->clock->now()->setTimezone($timezone);

        [$minDate, $maxDate] = $this->dateRange($now);
        $this->assertDateInRange($date, $minDate, $maxDate);

        $areaFilter = $this->optionalFilter($filters, 'area');
        $servicePeriodFilter = $this->optionalFilter($filters, 'service_period');

        $periods = $this->periodsForDate($restaurantId, $date, $timezone);
        $period = $this->resolveServicePeriod($periods, $time, $servicePeriodFilter);

        $this->assertPartySize($partySize);

        $cacheKey = $this->cacheKey($restaurantId, $date, $time, $partySize, $areaFilter, $servicePeriodFilter);
        $cached = $this->cacheGet($cacheKey);
        if ($cached !== null) {
            $cached['meta']['cached'] = true;

            return $cached;
        }

        $slotMinutes = $period['avg_dining_minutes'] + Slot::TURNOVER_BUFFER_MINUTES;
        $bookings = $this->occupyingBookings($restaurantId, $date);
        $tables = $this->bookableTables($restaurantId, $partySize, $areaFilter);

        $requestedEnd = Slot::addMinutes($time, $slotMinutes);
        $tablesAtRequestedTime = [];
        foreach ($tables as $table) {
            if ($this->tableIsFree($bookings, $table['id'], $time, $requestedEnd)) {
                $tablesAtRequestedTime[] = $table;
            }
        }

        $availableTables = count($tablesAtRequestedTime);
        $result = [
            'restaurant' => $restaurant,
            'query' => [
                'date' => $date,
                'time' => $time,
                'party_size' => $partySize,
                'area' => $areaFilter,
                'service_period' => $period['name'],
            ],
            'service_period' => [
                'id' => $period['id'],
                'name' => $period['name'],
                'start' => $period['start'],
                'end' => $period['end'],
                'avg_dining_minutes' => $period['avg_dining_minutes'],
                'slot_minutes' => $slotMinutes,
            ],
            'slots' => $this->buildSlots($period, $slotMinutes, $tables, $bookings),
            'tables' => $this->tableList($tablesAtRequestedTime),
            'summary' => [
                'available_tables' => $availableTables,
                'largest_party_size' => $this->largestPartySize($tablesAtRequestedTime),
                'is_available' => $availableTables > 0,
            ],
            'meta' => [
                'generated_at' => Slot::iso8601($now),
                'cached' => false,
                'cache_ttl_seconds' => $this->cacheTtlSeconds,
            ],
        ];

        $this->cachePut($cacheKey, $result);

        return $result;
    }

    /**
     * Drop the cached availability of one restaurant/day. BookingService calls
     * this right after a booking is committed (REQ-1.1 6.7).
     */
    public function invalidate(int $restaurantId, string $date): void
    {
        $prefix = $restaurantId . ':' . $date . ':';
        foreach (array_keys($this->cache) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->cache[$key]);
            }
        }
    }

    /**
     * Is one table free for [startTime, endTime) on that date? Used by
     * BookingService under its write lock. Back-to-back slots are free.
     */
    public function isTableFree(
        int $tableId,
        string $date,
        string $startTime,
        string $endTime,
        ?int $ignoreBookingId = null
    ): bool {
        $this->assertBookingInterval($date, $startTime, $endTime);

        $sql = 'SELECT id, start_time, end_time FROM bookings
                WHERE table_id = ? AND booking_date = ? AND status IN (?, ?)';
        $parameters = [
            $tableId,
            $date,
            self::OCCUPYING_BOOKING_STATUSES[0],
            self::OCCUPYING_BOOKING_STATUSES[1],
        ];
        if ($ignoreBookingId !== null) {
            $sql .= ' AND id <> ?';
            $parameters[] = $ignoreBookingId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($parameters);

        foreach ($stmt->fetchAll() as $booking) {
            if (Slot::overlaps(
                $startTime,
                $endTime,
                (string) $booking['start_time'],
                (string) $booking['end_time']
            )) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{id:int, name:string, timezone:string, address:string}
     * @throws DomainException
     */
    private function restaurant(int $restaurantId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, timezone, address FROM restaurants WHERE id = ?');
        $stmt->execute([$restaurantId]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            throw DomainException::notFound('Restaurant not found.');
        }

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'timezone' => (string) $row['timezone'],
            'address' => (string) $row['address'],
        ];
    }

    /**
     * The inclusive booking window in the restaurant's local time zone.
     *
     * @return array{0:string, 1:string} [today, today + MAX_DAYS_AHEAD]
     */
    private function dateRange(DateTimeImmutable $now): array
    {
        $today = $now->setTime(0, 0);

        return [
            $today->format('Y-m-d'),
            $today->modify('+' . Slot::MAX_DAYS_AHEAD . ' days')->format('Y-m-d'),
        ];
    }

    /**
     * @throws DomainException DATE_OUT_OF_RANGE
     */
    private function assertDateInRange(string $date, string $minDate, string $maxDate): void
    {
        if (Slot::isValidDate($date) && strcmp($date, $minDate) >= 0 && strcmp($date, $maxDate) <= 0) {
            return;
        }

        throw new DomainException(
            'DATE_OUT_OF_RANGE',
            sprintf('The requested date must be between %s and %s.', $minDate, $maxDate),
            ['min' => $minDate, 'max' => $maxDate],
            422
        );
    }

    /**
     * Service periods of the restaurant that run on the requested weekday,
     * ordered by start time.
     *
     * @return list<array{id:int, name:string, start:string, end:string, avg_dining_minutes:int}>
     */
    private function periodsForDate(int $restaurantId, string $date, DateTimeZone $timezone): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, start_time, end_time, avg_dining_minutes, days_of_week
             FROM service_periods WHERE restaurant_id = ? ORDER BY start_time, id'
        );
        $stmt->execute([$restaurantId]);

        $weekday = (int) Slot::at($date, '00:00', $timezone)->format('N');
        $periods = [];
        foreach ($stmt->fetchAll() as $row) {
            if (!$this->servesWeekday((string) $row['days_of_week'], $weekday)) {
                continue;
            }
            $periods[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'start' => (string) $row['start_time'],
                'end' => (string) $row['end_time'],
                'avg_dining_minutes' => (int) $row['avg_dining_minutes'],
            ];
        }

        return $periods;
    }

    private function servesWeekday(string $daysOfWeek, int $weekday): bool
    {
        $days = array_filter(
            array_map('trim', explode(',', $daysOfWeek)),
            static fn (string $day): bool => $day !== ''
        );
        if ($days === []) {
            return true;
        }
        foreach ($days as $day) {
            if ((int) $day === $weekday) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pick the service period that can host the whole requested slot.
     *
     * The slot is [time, time + avg_dining_minutes + 15), so the start must be
     * at or after the period start *and* early enough that the table is free
     * again when the restaurant closes. A start time in the tail of a period
     * would push the booking past closing and is rejected.
     *
     * @param list<array{id:int, name:string, start:string, end:string, avg_dining_minutes:int}> $periods
     * @return array{id:int, name:string, start:string, end:string, avg_dining_minutes:int}
     * @throws DomainException NO_SERVICE_PERIOD
     */
    private function resolveServicePeriod(array $periods, string $time, ?string $servicePeriodFilter): array
    {
        $latestStart = null;

        if (Slot::isValidTime($time)) {
            $requestedMinutes = Slot::minutes($time);

            foreach ($periods as $period) {
                if ($servicePeriodFilter !== null && strcasecmp($period['name'], $servicePeriodFilter) !== 0) {
                    continue;
                }

                $slotMinutes = $period['avg_dining_minutes'] + Slot::TURNOVER_BUFFER_MINUTES;
                $startMinutes = Slot::minutes($period['start']);
                $endMinutes = Slot::minutes($period['end']);
                $periodLatestStart = Slot::format($endMinutes - $slotMinutes);

                if ($requestedMinutes >= $startMinutes && $requestedMinutes + $slotMinutes <= $endMinutes) {
                    return $period;
                }

                if ($latestStart !== null) {
                    continue;
                }
                // A filtered period is the reference even when the time is
                // outside it; otherwise only the period containing the time is.
                $insidePeriod = $requestedMinutes >= $startMinutes && $requestedMinutes < $endMinutes;
                if ($servicePeriodFilter !== null || $insidePeriod) {
                    $latestStart = $periodLatestStart;
                }
            }
        }

        throw new DomainException(
            'NO_SERVICE_PERIOD',
            sprintf('No service period can host the requested slot starting at %s.', $time),
            [
                'requested_time' => $time,
                'latest_start' => $latestStart,
                'service_periods' => $this->servicePeriodSummaries($periods),
            ],
            422
        );
    }

    /**
     * @param list<array{id:int, name:string, start:string, end:string, avg_dining_minutes:int}> $periods
     * @return list<array{id:int, name:string, start:string, end:string}>
     */
    private function servicePeriodSummaries(array $periods): array
    {
        $summaries = [];
        foreach ($periods as $period) {
            $summaries[] = [
                'id' => $period['id'],
                'name' => $period['name'],
                'start' => $period['start'],
                'end' => $period['end'],
            ];
        }

        return $summaries;
    }

    /**
     * @throws DomainException VALIDATION_ERROR or PARTY_TOO_LARGE
     */
    private function assertPartySize(int $partySize): void
    {
        if ($partySize >= 1 && $partySize <= self::MAX_PARTY_SIZE) {
            return;
        }

        if ($partySize > self::MAX_PARTY_SIZE) {
            throw new DomainException(
                'PARTY_TOO_LARGE',
                sprintf(
                    'Online bookings seat at most %d guests; larger parties must call the restaurant.',
                    self::MAX_PARTY_SIZE
                ),
                [
                    'max_party_size' => self::MAX_PARTY_SIZE,
                    'telephone' => self::TELEPHONE_HINT,
                ],
                422
            );
        }

        throw DomainException::validation([
            'party_size' => sprintf('The party size must be between 1 and %d.', self::MAX_PARTY_SIZE),
        ]);
    }

    /**
     * @param array<string,mixed> $filters
     */
    private function optionalFilter(array $filters, string $key): ?string
    {
        $value = $filters[$key] ?? null;
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array<int, list<array{start:string, end:string}>>
     */
    private function occupyingBookings(int $restaurantId, string $date): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT table_id, start_time, end_time FROM bookings
             WHERE restaurant_id = ? AND booking_date = ? AND status IN (?, ?)'
        );
        $stmt->execute([
            $restaurantId,
            $date,
            self::OCCUPYING_BOOKING_STATUSES[0],
            self::OCCUPYING_BOOKING_STATUSES[1],
        ]);

        $bookings = [];
        foreach ($stmt->fetchAll() as $row) {
            $bookings[(int) $row['table_id']][] = [
                'start' => (string) $row['start_time'],
                'end' => (string) $row['end_time'],
            ];
        }

        return $bookings;
    }

    /**
     * Tables that may be offered at all: in service, big enough, in the area.
     *
     * @return list<array{id:int, code:string, seats:int, area:string}>
     */
    private function bookableTables(int $restaurantId, int $partySize, ?string $area): array
    {
        $sql = 'SELECT id, code, seats, area FROM tables
                WHERE restaurant_id = ? AND status = ? AND seats >= ?';
        $parameters = [$restaurantId, 'FREE', $partySize];
        if ($area !== null) {
            $sql .= ' AND LOWER(area) = LOWER(?)';
            $parameters[] = $area;
        }
        $sql .= ' ORDER BY code';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($parameters);

        $tables = [];
        foreach ($stmt->fetchAll() as $row) {
            $tables[] = [
                'id' => (int) $row['id'],
                'code' => (string) $row['code'],
                'seats' => (int) $row['seats'],
                'area' => (string) $row['area'],
            ];
        }

        return $tables;
    }

    /**
     * @param array<int, list<array{start:string, end:string}>> $bookings
     */
    private function tableIsFree(array $bookings, int $tableId, string $start, string $end): bool
    {
        foreach ($bookings[$tableId] ?? [] as $booking) {
            if (Slot::overlaps($start, $end, $booking['start'], $booking['end'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every aligned start time of the service period with its availability, so
     * the UI can suggest quieter alternatives.
     *
     * @param array{id:int, name:string, start:string, end:string, avg_dining_minutes:int} $period
     * @param list<array{id:int, code:string, seats:int, area:string}> $tables
     * @param array<int, list<array{start:string, end:string}>> $bookings
     * @return list<array{start:string, end:string, available_tables:int, largest_party_size:int}>
     */
    private function buildSlots(array $period, int $slotMinutes, array $tables, array $bookings): array
    {
        $firstStart = Slot::minutes($period['start']);
        $lastStart = Slot::minutes($period['end']) - $slotMinutes;

        $slots = [];
        for ($startMinutes = $firstStart; $startMinutes <= $lastStart; $startMinutes += Slot::STEP_MINUTES) {
            $start = Slot::format($startMinutes);
            $end = Slot::format($startMinutes + $slotMinutes);

            $available = [];
            foreach ($tables as $table) {
                if ($this->tableIsFree($bookings, $table['id'], $start, $end)) {
                    $available[] = $table;
                }
            }

            $slots[] = [
                'start' => $start,
                'end' => $end,
                'available_tables' => count($available),
                'largest_party_size' => $this->largestPartySize($available),
            ];
        }

        return $slots;
    }

    /**
     * @param list<array{id:int, code:string, seats:int, area:string}> $tables
     */
    private function largestPartySize(array $tables): int
    {
        $largest = 0;
        foreach ($tables as $table) {
            if ($table['seats'] > $largest) {
                $largest = $table['seats'];
            }
        }

        return $largest;
    }

    /**
     * @param list<array{id:int, code:string, seats:int, area:string}> $tables
     * @return list<array{id:int, code:string, seats:int, area:string}>
     */
    private function tableList(array $tables): array
    {
        $list = [];
        foreach ($tables as $table) {
            $list[] = [
                'id' => $table['id'],
                'code' => $table['code'],
                'seats' => $table['seats'],
                'area' => $table['area'],
            ];
        }

        return $list;
    }

    private function cacheKey(
        int $restaurantId,
        string $date,
        string $time,
        int $partySize,
        ?string $area,
        ?string $servicePeriod
    ): string {
        $fingerprint = (string) json_encode([$time, $partySize, $area, $servicePeriod]);

        return $restaurantId . ':' . $date . ':' . md5($fingerprint);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function cacheGet(string $key): ?array
    {
        if (!isset($this->cache[$key])) {
            return null;
        }

        $entry = $this->cache[$key];
        if ($entry['expires_at'] <= $this->clock->now()->getTimestamp()) {
            unset($this->cache[$key]);

            return null;
        }

        return $entry['result'];
    }

    /**
     * @param array<string,mixed> $result
     */
    private function cachePut(string $key, array $result): void
    {
        if ($this->cacheTtlSeconds <= 0) {
            return;
        }

        $this->cache[$key] = [
            'expires_at' => $this->clock->now()->getTimestamp() + $this->cacheTtlSeconds,
            'result' => $result,
        ];
    }

    /**
     * @throws DomainException
     */
    private function assertBookingInterval(string $date, string $startTime, string $endTime): void
    {
        $errors = [];
        if (!Slot::isValidDate($date)) {
            $errors['date'] = 'The date must use the YYYY-MM-DD format.';
        }
        if (!Slot::isValidTime($startTime)) {
            $errors['start_time'] = 'The start time must use the HH:MM format.';
        }
        if (!Slot::isValidTime($endTime)) {
            $errors['end_time'] = 'The end time must use the HH:MM format.';
        }
        if ($errors !== []) {
            throw DomainException::validation($errors);
        }
    }
}

<?php

declare(strict_types=1);

namespace DineReserve;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Reporting for URS REQ-1.7: occupancy, utilisation, no-show rate and channel
 * mix per day, plus the no-show risk distribution (REQ-1.8).
 *
 * Everything is derived from the booking rows and their events — the same data
 * the audit trail uses — so the numbers can always be traced back.
 */
final class ReportService
{
    /** A single export may cover at most one quarter. */
    public const MAX_RANGE_DAYS = 92;

    /** Bookings in these states were actually served (covers). */
    private const SERVED_STATUSES = ['CONFIRMED', 'SEATED', 'COMPLETED'];

    private PDO $pdo;

    private Clock $clock;

    public function __construct(PDO $pdo, ?Clock $clock = null)
    {
        $this->pdo = $pdo;
        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * Daily occupancy / utilisation / no-show / channel report.
     *
     * @return array<string,mixed>
     */
    public function occupancy(int $restaurantId, string $from, string $to): array
    {
        $restaurant = $this->validateRange($restaurantId, $from, $to);
        $timezone = new DateTimeZone((string) $restaurant['timezone']);
        $capacityPerDay = $this->capacityPerDay($restaurantId);

        $statement = $this->pdo->prepare(
            'SELECT booking_date, start_time, party_size, status, channel
               FROM bookings
              WHERE restaurant_id = ? AND booking_date BETWEEN ? AND ?
              ORDER BY booking_date, start_time'
        );
        $statement->execute([$restaurantId, $from, $to]);
        $bookings = $statement->fetchAll(PDO::FETCH_ASSOC);

        $days = [];
        $cursor = new DateTimeImmutable($from);
        $end = new DateTimeImmutable($to);
        while ($cursor <= $end) {
            $date = $cursor->format('Y-m-d');
            $days[$date] = [
                'date' => $date,
                'covers' => 0,
                'capacity' => $capacityPerDay,
                'utilisation_pct' => 0.0,
                'bookings' => 0,
                'no_shows' => 0,
                'cancellations' => 0,
                'no_show_rate_pct' => 0.0,
                'channels' => ['online' => 0, 'phone' => 0, 'walk_in' => 0, 'waitlist' => 0],
            ];
            $cursor = $cursor->modify('+1 day');
        }

        foreach ($bookings as $booking) {
            $date = (string) $booking['booking_date'];
            if (!isset($days[$date])) {
                continue;
            }
            $day = &$days[$date];
            $status = strtoupper((string) $booking['status']);
            $channel = strtolower((string) $booking['channel']);
            if (isset($day['channels'][$channel])) {
                $day['channels'][$channel]++;
            }
            $day['bookings']++;
            if (in_array($status, self::SERVED_STATUSES, true)) {
                $day['covers'] += (int) $booking['party_size'];
            } elseif ($status === 'NO_SHOW') {
                $day['no_shows']++;
            } elseif ($status === 'CANCELLED') {
                $day['cancellations']++;
            }
            unset($day);
        }

        $totals = [
            'covers' => 0,
            'capacity' => 0,
            'bookings' => 0,
            'no_shows' => 0,
            'cancellations' => 0,
            'channels' => ['online' => 0, 'phone' => 0, 'walk_in' => 0, 'waitlist' => 0],
        ];
        $list = [];
        foreach ($days as $date => $day) {
            $day['utilisation_pct'] = $day['capacity'] > 0
                ? round($day['covers'] / $day['capacity'] * 100, 1)
                : 0.0;
            $servedOrMissed = $day['bookings'] - $day['cancellations'];
            $day['no_show_rate_pct'] = $servedOrMissed > 0
                ? round($day['no_shows'] / $servedOrMissed * 100, 1)
                : 0.0;
            $totals['covers'] += $day['covers'];
            $totals['capacity'] += $day['capacity'];
            $totals['bookings'] += $day['bookings'];
            $totals['no_shows'] += $day['no_shows'];
            $totals['cancellations'] += $day['cancellations'];
            foreach ($day['channels'] as $channel => $count) {
                $totals['channels'][$channel] += $count;
            }
            $list[] = $day;
        }
        $totals['utilisation_pct'] = $totals['capacity'] > 0
            ? round($totals['covers'] / $totals['capacity'] * 100, 1)
            : 0.0;
        $servedOrMissed = $totals['bookings'] - $totals['cancellations'];
        $totals['no_show_rate_pct'] = $servedOrMissed > 0
            ? round($totals['no_shows'] / $servedOrMissed * 100, 1)
            : 0.0;

        return [
            'kind' => 'occupancy',
            'restaurant' => [
                'id' => (int) $restaurant['id'],
                'name' => (string) $restaurant['name'],
                'timezone' => (string) $restaurant['timezone'],
            ],
            'from' => $from,
            'to' => $to,
            'generated_at' => Slot::iso8601($this->clock->now()->setTimezone($timezone)),
            'capacity_per_day' => $capacityPerDay,
            'days' => $list,
            'totals' => $totals,
        ];
    }

    /**
     * No-show risk distribution and the flagged bookings (REQ-1.8).
     *
     * @return array<string,mixed>
     */
    public function risk(int $restaurantId, string $from, string $to): array
    {
        $restaurant = $this->validateRange($restaurantId, $from, $to);

        $statement = $this->pdo->prepare(
            'SELECT reference, booking_date, start_time, party_size, channel, status,
                    risk_score, risk_rule_version
               FROM bookings
              WHERE restaurant_id = ? AND booking_date BETWEEN ? AND ? AND risk_score IS NOT NULL
              ORDER BY risk_score DESC, booking_date'
        );
        $statement->execute([$restaurantId, $from, $to]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        $buckets = ['HIGH' => 0, 'MEDIUM' => 0, 'LOW' => 0];
        $sum = 0;
        $flagged = [];
        $versions = [];
        foreach ($rows as $row) {
            $score = (int) $row['risk_score'];
            $sum += $score;
            $band = $score >= RiskScorer::HIGH_THRESHOLD
                ? 'HIGH'
                : ($score >= RiskScorer::MEDIUM_THRESHOLD ? 'MEDIUM' : 'LOW');
            $buckets[$band]++;
            $versions[(string) $row['risk_rule_version']] = true;
            if ($band === 'HIGH') {
                $flagged[] = [
                    'reference' => (string) $row['reference'],
                    'date' => (string) $row['booking_date'],
                    'time' => (string) $row['start_time'],
                    'party_size' => (int) $row['party_size'],
                    'channel' => (string) $row['channel'],
                    'status' => (string) $row['status'],
                    'score' => $score,
                    'rule_version' => (string) $row['risk_rule_version'],
                ];
            }
        }

        return [
            'kind' => 'risk',
            'restaurant' => [
                'id' => (int) $restaurant['id'],
                'name' => (string) $restaurant['name'],
            ],
            'from' => $from,
            'to' => $to,
            'generated_at' => Slot::iso8601($this->clock->now()),
            'scored_bookings' => count($rows),
            'average_score' => $rows === [] ? 0.0 : round($sum / count($rows), 1),
            'distribution' => $buckets,
            'rule_versions' => array_keys($versions),
            'flagged' => $flagged,
        ];
    }

    // ------------------------------------------------------------ helpers

    /** @return array<string,mixed> */
    private function validateRange(int $restaurantId, string $from, string $to): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM restaurants WHERE id = ?');
        $statement->execute([$restaurantId]);
        $restaurant = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($restaurant)) {
            throw DomainException::notFound('Restaurant not found.');
        }
        if (!Slot::isValidDate($from) || !Slot::isValidDate($to)) {
            throw DomainException::validation(['range' => 'Both dates must be real dates in YYYY-MM-DD format.']);
        }
        if ($from > $to) {
            throw DomainException::validation(['range' => 'The start date must not be after the end date.']);
        }
        $days = (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days;
        if ($days > self::MAX_RANGE_DAYS) {
            throw new DomainException(
                'RANGE_TOO_LARGE',
                'A report may cover at most ' . self::MAX_RANGE_DAYS . ' days.',
                ['max_days' => self::MAX_RANGE_DAYS, 'requested_days' => $days],
                422
            );
        }

        return $restaurant;
    }

    /**
     * Capacity in covers per day: bookable seats multiplied by how many sittings
     * the service periods allow (dining time + turnover buffer).
     */
    private function capacityPerDay(int $restaurantId): int
    {
        $tables = $this->pdo->prepare(
            'SELECT COALESCE(SUM(seats), 0) FROM tables WHERE restaurant_id = ? AND status NOT IN (?, ?)'
        );
        $tables->execute([$restaurantId, 'BLOCKED', 'MAINTENANCE']);
        $seats = (int) $tables->fetchColumn();

        $periods = $this->pdo->prepare(
            'SELECT start_time, end_time, avg_dining_minutes FROM service_periods WHERE restaurant_id = ?'
        );
        $periods->execute([$restaurantId]);
        $turns = 0;
        foreach ($periods->fetchAll(PDO::FETCH_ASSOC) as $period) {
            $minutes = Slot::minutes((string) $period['end_time']) - Slot::minutes((string) $period['start_time']);
            $sitting = (int) $period['avg_dining_minutes'] + Slot::TURNOVER_BUFFER_MINUTES;
            $turns += max(1, intdiv(max(0, $minutes - (int) $period['avg_dining_minutes']), max(1, $sitting)) + 1);
        }
        if ($turns === 0) {
            $turns = 1;
        }

        return $seats * $turns;
    }
}

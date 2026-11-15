<?php

declare(strict_types=1);

namespace DineReserve;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * Explainable no-show risk score for URS REQ-1.8.
 *
 * The score is additive: every rule contributes a small number of points and
 * each contribution is returned with the score, so the host can see *why* a
 * booking is flagged. The rule set carries a version that is stored with the
 * score, so historical scores can be explained after the rules change.
 *
 * Bands: HIGH >= 70, MEDIUM >= 40, LOW below.
 */
final class RiskScorer
{
    /** Version of this rule set; stored on every scored booking. */
    public const RULE_VERSION = 'risk-v1';

    public const HIGH_THRESHOLD = 70;
    public const MEDIUM_THRESHOLD = 40;

    private const BASE_POINTS = 10;

    /** @var list<array{code:string,points:int,label:string}> */
    private array $reasons = [];

    private PDO $pdo;

    private Clock $clock;

    public function __construct(PDO $pdo, ?Clock $clock = null)
    {
        $this->pdo = $pdo;
        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * Score a prospective booking.
     *
     * @param array{restaurant_id:int,date:string,time:string,party_size:int,channel?:string,email?:string,special_requests?:?string} $context
     * @param int|null $ignoreBookingId exclude a booking from its own history
     * @return array{score:int,band:string,reasons:list<array{code:string,points:int,label:string}>,rule_version:string}
     */
    public function score(array $context, ?int $ignoreBookingId = null): array
    {
        $this->reasons = [];
        $points = self::BASE_POINTS;
        $this->reasons[] = ['code' => 'baseline', 'points' => self::BASE_POINTS, 'label' => 'Baseline risk for any booking'];

        $restaurantId = (int) ($context['restaurant_id'] ?? 0);
        $restaurant = $this->findRestaurant($restaurantId);
        $timezone = new DateTimeZone($restaurant === false ? 'Asia/Macau' : (string) $restaurant['timezone']);

        $date = (string) ($context['date'] ?? '');
        $time = (string) ($context['time'] ?? '');
        if (!Slot::isValidDate($date) || !Slot::isValidTime($time)) {
            return $this->result(self::BASE_POINTS);
        }

        $start = Slot::at($date, $time, $timezone);
        $now = $this->clock->now()->setTimezone($timezone);
        $leadHours = ($start->getTimestamp() - $now->getTimestamp()) / 3600;

        if ($leadHours < 24) {
            $points += $this->reason('short_lead', 15, 'Booked less than 24 hours ahead');
        } elseif ($leadHours < 72) {
            $points += $this->reason('medium_lead', 10, 'Booked 24–72 hours ahead');
        } elseif ($leadHours < 168) {
            $points += $this->reason('week_lead', 5, 'Booked 3–7 days ahead');
        }

        $weekday = (int) $start->format('N');
        if (in_array($weekday, [5, 6], true)) {
            $points += $this->reason('peak_weekday', 10, 'Friday or Saturday sitting');
        } elseif ($weekday === 7) {
            $points += $this->reason('weekend', 5, 'Sunday sitting');
        }

        if (Slot::minutes($time) >= Slot::minutes('20:00')) {
            $points += $this->reason('late_sitting', 5, 'Late dinner start (20:00 or later)');
        }

        $partySize = (int) ($context['party_size'] ?? 0);
        if ($partySize >= 8) {
            $points += $this->reason('large_party', 12, 'Party of 8 or more');
        } elseif ($partySize >= 6) {
            $points += $this->reason('group', 8, 'Party of 6 or more');
        }

        $channel = strtolower((string) ($context['channel'] ?? 'online'));
        $points += match ($channel) {
            'online' => $this->reason('channel_online', 5, 'Self-service online booking'),
            'phone' => $this->reason('channel_phone', -5, 'Booked by telephone with the host'),
            'walk_in' => $this->reason('channel_walk_in', -10, 'Walk-in guest, already on site'),
            'waitlist' => $this->reason('channel_waitlist', -5, 'Converted from the waitlist offer'),
            default => 0,
        };

        $email = strtolower(trim((string) ($context['email'] ?? '')));
        if ($email !== '') {
            $history = $this->history($restaurantId, $email, $ignoreBookingId);
            if ($history['no_shows'] > 0) {
                $penalty = min(30, $history['no_shows'] * 15);
                $points += $this->reason(
                    'past_no_shows',
                    $penalty,
                    'Guest has ' . $history['no_shows'] . ' previous no-show(s)'
                );
            } elseif ($history['bookings'] >= 3) {
                $points += $this->reason('loyal_guest', -5, 'Regular guest with a clean history');
            }
        }

        if (trim((string) ($context['special_requests'] ?? '')) !== '') {
            $points += $this->reason('special_request', -3, 'Specific request given at booking time');
        }

        return $this->result($points);
    }

    /**
     * Score a stored booking and persist the result (REQ-1.8 keeps the score and
     * the rule version on the booking row).
     *
     * @return array{score:int,band:string,reasons:list<array{code:string,points:int,label:string}>,rule_version:string}
     */
    public function applyToBooking(int $bookingId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM bookings WHERE id = ?');
        $statement->execute([$bookingId]);
        $booking = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($booking)) {
            throw DomainException::notFound('Booking not found.');
        }

        $score = $this->score([
            'restaurant_id' => (int) $booking['restaurant_id'],
            'date' => (string) $booking['booking_date'],
            'time' => (string) $booking['start_time'],
            'party_size' => (int) $booking['party_size'],
            'channel' => (string) $booking['channel'],
            'email' => (string) $booking['guest_email'],
            'special_requests' => $booking['special_requests'],
        ], $bookingId);

        $this->persist($bookingId, $score);
        $this->audit($bookingId, $score);

        return $score;
    }

    /**
     * Re-score every booking in a date range (used after a rule change).
     */
    public function rescoreRange(string $from, string $to, ?int $restaurantId = null): int
    {
        $sql = 'SELECT id FROM bookings WHERE booking_date BETWEEN ? AND ?';
        $parameters = [$from, $to];
        if ($restaurantId !== null) {
            $sql .= ' AND restaurant_id = ?';
            $parameters[] = $restaurantId;
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $count = 0;
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $this->applyToBooking((int) $id);
            $count++;
        }

        return $count;
    }

    // ------------------------------------------------------------ helpers

    private function reason(string $code, int $points, string $label): int
    {
        $this->reasons[] = ['code' => $code, 'points' => $points, 'label' => $label];

        return $points;
    }

    /** @return array{score:int,band:string,reasons:list<array{code:string,points:int,label:string}>,rule_version:string} */
    private function result(int $points): array
    {
        $score = max(0, min(100, $points));

        return [
            'score' => $score,
            'band' => $score >= self::HIGH_THRESHOLD ? 'HIGH' : ($score >= self::MEDIUM_THRESHOLD ? 'MEDIUM' : 'LOW'),
            'reasons' => $this->reasons,
            'rule_version' => self::RULE_VERSION,
        ];
    }

    /** @return array{bookings:int,no_shows:int} */
    private function history(int $restaurantId, string $email, ?int $ignoreBookingId): array
    {
        $sql = 'SELECT COUNT(*) AS bookings,
                       SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS no_shows
                  FROM bookings
                 WHERE restaurant_id = ? AND guest_email = ?';
        $parameters = ['NO_SHOW', $restaurantId, $email];
        if ($ignoreBookingId !== null) {
            $sql .= ' AND id <> ?';
            $parameters[] = $ignoreBookingId;
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return [
            'bookings' => (int) ($row['bookings'] ?? 0),
            'no_shows' => (int) ($row['no_shows'] ?? 0),
        ];
    }

    /** @param array{score:int,band:string,reasons:list<array<string,mixed>>,rule_version:string} $score */
    private function persist(int $bookingId, array $score): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE bookings SET risk_score = ?, risk_rule_version = ?, updated_at = ? WHERE id = ?'
        );
        $statement->execute([
            $score['score'],
            $score['rule_version'],
            Database::dateTimeFor($this->pdo, Slot::iso8601($this->clock->now())),
            $bookingId,
        ]);
    }

    /** @param array{score:int,band:string,reasons:list<array<string,mixed>>,rule_version:string} $score */
    private function audit(int $bookingId, array $score): void
    {
        try {
            $this->pdo->prepare(
                'INSERT INTO audit_log (actor, action, entity, entity_id, payload, occurred_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                'system',
                'booking.risk_scored',
                'booking',
                (string) $bookingId,
                json_encode($score, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                Database::dateTimeFor($this->pdo, Slot::iso8601($this->clock->now())),
            ]);
        } catch (Throwable) {
            // Audit is best effort.
        }
    }

    /** @return array<string,mixed>|false */
    private function findRestaurant(int $restaurantId): array|false
    {
        $statement = $this->pdo->prepare('SELECT * FROM restaurants WHERE id = ?');
        $statement->execute([$restaurantId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : false;
    }
}

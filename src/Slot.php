<?php

declare(strict_types=1);

namespace DineReserve;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Time arithmetic for the booking domain. Everything is expressed in the
 * restaurant's local wall clock ("HH:MM" strings) plus a "YYYY-MM-DD" date,
 * which is how the URS describes a booking.
 */
final class Slot
{
    /** Turnover buffer between two bookings of the same table (URS REQ-1.1 6.4). */
    public const TURNOVER_BUFFER_MINUTES = 15;

    /** Allowed search/booking horizon in days (URS REQ-1.1 6.9: max 90 days ahead). */
    public const MAX_DAYS_AHEAD = 90;

    /** Granularity of offered start times. */
    public const STEP_MINUTES = 15;

    /** Default policy values (URS REQ-1.9 / 4). */
    public const DEFAULT_CUTOFF_MINUTES = 120;

    public static function minutes(string $hhmm): int
    {
        if (preg_match('/^(\d{2}):(\d{2})$/', $hhmm, $m) !== 1) {
            throw new \InvalidArgumentException("Invalid HH:MM time: {$hhmm}");
        }
        $hours = (int) $m[1];
        $minutes = (int) $m[2];
        if ($hours > 24 || $minutes > 59) {
            throw new \InvalidArgumentException("Invalid HH:MM time: {$hhmm}");
        }

        return $hours * 60 + $minutes;
    }

    public static function format(int $minutes): string
    {
        $minutes = max(0, $minutes);
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return sprintf('%02d:%02d', $hours, $rest);
    }

    public static function isValidDate(string $date): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return false;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $date));

        return checkdate($month, $day, $year);
    }

    public static function isValidTime(string $time): bool
    {
        try {
            self::minutes($time);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    public static function at(string $date, string $time, DateTimeZone $timezone): DateTimeImmutable
    {
        return new DateTimeImmutable($date . ' ' . $time . ':00', $timezone);
    }

    public static function addMinutes(string $time, int $minutes): string
    {
        return self::format(self::minutes($time) + $minutes);
    }

    public static function duration(string $start, string $end): int
    {
        return self::minutes($end) - self::minutes($start);
    }

    /**
     * Two half-open intervals [startA, endA) and [startB, endB) overlap.
     * Back-to-back bookings (A ends exactly when B starts) do not overlap.
     */
    public static function overlaps(string $startA, string $endA, string $startB, string $endB): bool
    {
        return self::minutes($startA) < self::minutes($endB)
            && self::minutes($startB) < self::minutes($endA);
    }

    public static function contains(string $periodStart, string $periodEnd, string $time): bool
    {
        return self::minutes($periodStart) <= self::minutes($time)
            && self::minutes($time) < self::minutes($periodEnd);
    }

    public static function iso8601(DateTimeImmutable $moment): string
    {
        return $moment->format('Y-m-d\TH:i:sP');
    }
}

<?php

declare(strict_types=1);

namespace DineReserve;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * Outbound notification pipeline for URS REQ-1.4:
 *
 *  - a confirmation message when a booking is created,
 *  - reminders 24 h and 2 h before the sitting,
 *  - change / cancellation notices,
 *  - retries with a 1 / 5 / 15 minute back-off, then FAILED,
 *  - one audit row per attempt family in `notification_log`.
 *
 * Delivery is delegated to a Mailer port, so the queue can be exercised in
 * tests and CI without touching an SMTP server.
 */
final class NotificationService
{
    public const TEMPLATE_CONFIRMATION = 'booking_confirmation';
    public const TEMPLATE_CHANGED = 'booking_changed';
    public const TEMPLATE_CANCELLED = 'booking_cancelled';
    public const TEMPLATE_REMINDER_24H = 'booking_reminder_24h';
    public const TEMPLATE_REMINDER_2H = 'booking_reminder_2h';

    public const STATUS_QUEUED = 'QUEUED';
    public const STATUS_SENT = 'SENT';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_CANCELLED = 'CANCELLED';

    public const CHANNEL_EMAIL = 'email';

    /** Reminder offsets in hours before the sitting (URS REQ-1.4 §9). */
    public const REMINDER_OFFSETS = [24, 2];

    /** Retry intervals in minutes after a failed attempt (URS REQ-1.4 §9). */
    public const RETRY_DELAYS_MINUTES = [1, 5, 15];

    /** A row is FAILED after the initial attempt plus the retries above. */
    public const MAX_ATTEMPTS = 4;

    private PDO $pdo;

    private Mailer $mailer;

    private Clock $clock;

    /** @var Closure(string):string|null */
    private ?Closure $tokenFactory;

    /**
     * @param callable(string):string|null $tokenFactory signed manage-link factory
     */
    public function __construct(PDO $pdo, Mailer $mailer, ?Clock $clock = null, ?callable $tokenFactory = null)
    {
        $this->pdo = $pdo;
        $this->mailer = $mailer;
        $this->clock = $clock ?? new SystemClock();
        $this->tokenFactory = $tokenFactory === null ? null : Closure::fromCallable($tokenFactory);
    }

    /**
     * Queue the 24 h and 2 h reminders for a booking. Reminders whose moment
     * has already passed are skipped (e.g. a booking made for this evening),
     * and existing rows are never duplicated.
     *
     * @return int number of reminder rows inserted
     */
    public function scheduleRemindersForBooking(int $bookingId): int
    {
        $booking = $this->fetchBooking($bookingId);
        if ($booking === false || strtoupper((string) $booking['status']) !== 'CONFIRMED') {
            return 0;
        }

        $restaurant = $this->fetchRestaurant((int) $booking['restaurant_id']);
        $timezone = new DateTimeZone($restaurant === false ? 'Asia/Macau' : (string) $restaurant['timezone']);
        $start = Slot::at((string) $booking['booking_date'], (string) $booking['start_time'], $timezone);
        $now = $this->clock->now()->setTimezone($timezone);

        $inserted = 0;
        foreach (self::REMINDER_OFFSETS as $hours) {
            $template = $hours === 24 ? self::TEMPLATE_REMINDER_24H : self::TEMPLATE_REMINDER_2H;
            $reminderAt = $start->modify('-' . $hours . ' hours');
            if ($reminderAt <= $now) {
                continue;
            }
            if ($this->hasRow($bookingId, $template)) {
                continue;
            }

            $this->insertRow($bookingId, $template, self::STATUS_QUEUED, $reminderAt);
            $inserted++;
        }

        return $inserted;
    }

    /**
     * After a change, drop the reminders of the old slot and queue new ones.
     */
    public function rescheduleRemindersForBooking(int $bookingId): int
    {
        $this->cancelPendingReminders($bookingId);

        return $this->scheduleRemindersForBooking($bookingId);
    }

    /** Mark every queued reminder of a booking as CANCELLED. */
    public function cancelPendingReminders(int $bookingId): int
    {
        $statement = $this->pdo->prepare(
            'UPDATE notification_log
                SET status = ?, last_error = ?
              WHERE booking_id = ? AND status = ? AND template LIKE ?'
        );
        $statement->execute([
            self::STATUS_CANCELLED,
            'cancelled because the booking changed or was cancelled',
            $bookingId,
            self::STATUS_QUEUED,
            'booking_reminder%',
        ]);

        return $statement->rowCount();
    }

    /**
     * Deliver every due notification once.
     *
     * Each row is isolated: a failing render or delivery never stops the batch.
     * A failure increments `attempts` and re-schedules the row 1 / 5 / 15
     * minutes later; after MAX_ATTEMPTS the row becomes FAILED.
     *
     * @return array{sent:int,failed:int,retried:int,cancelled:int,due:int}
     */
    public function dispatch(int $limit = 50): array
    {
        $limit = max(1, $limit);
        $now = $this->clock->now();
        $nowStorage = $this->storageDateTime(Slot::iso8601($now));

        $statement = $this->pdo->prepare(
            'SELECT * FROM notification_log
              WHERE status = ? AND scheduled_at IS NOT NULL AND scheduled_at <= ?
              ORDER BY scheduled_at, id
              LIMIT ' . $limit
        );
        $statement->execute([self::STATUS_QUEUED, $nowStorage]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        $summary = ['sent' => 0, 'failed' => 0, 'retried' => 0, 'cancelled' => 0, 'due' => count($rows)];

        foreach ($rows as $row) {
            try {
                $booking = $this->fetchBooking((int) $row['booking_id']);
                if ($booking === false || $this->isStale($booking, (string) $row['template'])) {
                    $this->markStatus((int) $row['id'], self::STATUS_CANCELLED, 'booking no longer eligible', $now);
                    $summary['cancelled']++;

                    continue;
                }

                $restaurant = $this->fetchRestaurant((int) $booking['restaurant_id']);
                $table = $this->fetchTable((int) $booking['table_id']);
                $message = $this->render($booking, $restaurant ?: [], $table ?: [], (string) $row['template']);

                $this->mailer->send(
                    (string) $booking['guest_email'],
                    $message['subject'],
                    $message['body']
                );

                $this->markSent((int) $row['id'], $now, (int) $row['attempts']);
                $summary['sent']++;
            } catch (Throwable $exception) {
                $attempts = (int) $row['attempts'] + 1;
                if ($attempts >= self::MAX_ATTEMPTS) {
                    $this->markFailed((int) $row['id'], $exception->getMessage(), $attempts, $now);
                    $summary['failed']++;

                    continue;
                }

                $delay = self::RETRY_DELAYS_MINUTES[$attempts - 1];
                $this->reschedule(
                    (int) $row['id'],
                    $exception->getMessage(),
                    $attempts,
                    $now->modify('+' . $delay . ' minutes')
                );
                $summary['retried']++;
            }
        }

        return $summary;
    }

    /**
     * Rows that are due right now — used by `bin/notify.php --dry-run`.
     *
     * @return list<array<string,mixed>>
     */
    public function due(int $limit = 50): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM notification_log
              WHERE status = ? AND scheduled_at IS NOT NULL AND scheduled_at <= ?
              ORDER BY scheduled_at, id
              LIMIT ' . max(1, $limit)
        );
        $statement->execute([self::STATUS_QUEUED, $this->storageDateTime(Slot::iso8601($this->clock->now()))]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Render a template to a subject/body pair (REQ-1.4 §9).
     *
     * @param array<string,mixed> $booking
     * @param array<string,mixed> $restaurant
     * @param array<string,mixed> $table
     * @return array{subject:string,body:string}
     */
    public function render(array $booking, array $restaurant, array $table, string $template): array
    {
        $timezone = new DateTimeZone((string) ($restaurant['timezone'] ?? 'Asia/Macau'));
        $start = Slot::at((string) $booking['booking_date'], (string) $booking['start_time'], $timezone);
        $restaurantName = (string) ($restaurant['name'] ?? 'DineReserve');

        $data = [
            'name' => (string) $booking['guest_name'],
            'reference' => (string) $booking['reference'],
            'restaurant' => $restaurantName,
            'date' => $start->format('D, j M Y'),
            'time' => (string) $booking['start_time'] . ' – ' . (string) $booking['end_time'],
            'party' => (int) $booking['party_size'],
            'table' => (string) ($table['code'] ?? '?'),
            'area' => (string) ($table['area'] ?? 'main'),
        ];

        $firstLine = match ($template) {
            self::TEMPLATE_CONFIRMATION => 'Your booking is confirmed.',
            self::TEMPLATE_CHANGED => 'Your booking has been changed.',
            self::TEMPLATE_CANCELLED => 'Your booking has been cancelled.',
            self::TEMPLATE_REMINDER_24H => 'Your table is tomorrow — see you soon.',
            self::TEMPLATE_REMINDER_2H => 'Your table is in two hours.',
            default => 'Your booking at ' . $restaurantName . '.',
        };

        $subject = match ($template) {
            self::TEMPLATE_CONFIRMATION => $restaurantName . ' booking confirmed — ' . $data['reference'],
            self::TEMPLATE_CHANGED => $restaurantName . ' booking changed — ' . $data['reference'],
            self::TEMPLATE_CANCELLED => $restaurantName . ' booking cancelled — ' . $data['reference'],
            self::TEMPLATE_REMINDER_24H => $restaurantName . ' — your table is tomorrow',
            self::TEMPLATE_REMINDER_2H => $restaurantName . ' — your table is in 2 hours',
            default => $restaurantName . ' — ' . $data['reference'],
        };

        $lines = [
            'Dear ' . $data['name'] . ',',
            '',
            $firstLine,
            '',
            'Reference : ' . $data['reference'],
            'Restaurant: ' . $data['restaurant'],
            'Date      : ' . $data['date'],
            'Time      : ' . $data['time'],
            'Guests    : ' . $data['party'],
            'Table     : ' . $data['table'] . ' (' . $data['area'] . ')',
        ];

        if ($template !== self::TEMPLATE_CANCELLED) {
            $link = $this->manageLink((string) $booking['reference']);
            if ($link !== null) {
                $lines[] = '';
                $lines[] = 'Change or cancel: ' . $link;
            }
        }

        $lines[] = '';
        $lines[] = 'If you do not recognise this booking, please contact the restaurant.';

        return ['subject' => $subject, 'body' => implode("\n", $lines)];
    }

    /** @param array<string,mixed> $booking */
    private function isStale(array $booking, string $template): bool
    {
        $status = strtoupper((string) $booking['status']);
        if (!str_starts_with($template, 'booking_reminder')) {
            return false;
        }

        return $status !== 'CONFIRMED';
    }

    private function manageLink(string $reference): ?string
    {
        if ($this->tokenFactory === null) {
            return null;
        }
        try {
            $token = ($this->tokenFactory)($reference);
        } catch (Throwable) {
            return null;
        }
        $base = getenv('NOTIFICATION_BASE_URL');
        if (!is_string($base) || trim($base) === '') {
            $base = 'http://127.0.0.1:8080';
        }

        return rtrim($base, '/') . '/api/v1/bookings/' . $reference . '/manage?token=' . rawurlencode($token);
    }

    /**
     * True when an active (queued or sent) row already covers this template.
     * Cancelled rows are ignored so a changed booking can be re-scheduled.
     */
    private function hasRow(int $bookingId, string $template): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM notification_log
              WHERE booking_id = ? AND template = ? AND status IN (?, ?)
              LIMIT 1'
        );
        $statement->execute([$bookingId, $template, self::STATUS_QUEUED, self::STATUS_SENT]);

        return $statement->fetchColumn() !== false;
    }

    private function insertRow(int $bookingId, string $template, string $status, DateTimeImmutable $scheduledAt): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO notification_log (booking_id, channel, template, status, attempts, scheduled_at)
             VALUES (?, ?, ?, ?, 0, ?)'
        );
        $statement->execute([
            $bookingId,
            self::CHANNEL_EMAIL,
            $template,
            $status,
            $this->storageDateTime(Slot::iso8601($scheduledAt)),
        ]);
    }

    private function markSent(int $id, DateTimeImmutable $now, int $attempts): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE notification_log SET status = ?, attempts = ?, sent_at = ?, last_error = NULL WHERE id = ?'
        );
        $statement->execute([self::STATUS_SENT, $attempts + 1, $this->storageDateTime(Slot::iso8601($now)), $id]);
    }

    private function markStatus(int $id, string $status, ?string $error, DateTimeImmutable $now): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE notification_log SET status = ?, last_error = ?, sent_at = ? WHERE id = ?'
        );
        $statement->execute([$status, $error, $this->storageDateTime(Slot::iso8601($now)), $id]);
    }

    private function markFailed(int $id, string $error, int $attempts, DateTimeImmutable $now): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE notification_log SET status = ?, attempts = ?, last_error = ? WHERE id = ?'
        );
        $statement->execute([self::STATUS_FAILED, $attempts, substr($error, 0, 255), $id]);
    }

    private function reschedule(int $id, string $error, int $attempts, DateTimeImmutable $nextAttempt): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE notification_log SET status = ?, attempts = ?, last_error = ?, scheduled_at = ? WHERE id = ?'
        );
        $statement->execute([
            self::STATUS_QUEUED,
            $attempts,
            substr($error, 0, 255),
            $this->storageDateTime(Slot::iso8601($nextAttempt)),
            $id,
        ]);
    }

    /** @return array<string,mixed>|false */
    private function fetchBooking(int $bookingId): array|false
    {
        $statement = $this->pdo->prepare('SELECT * FROM bookings WHERE id = ?');
        $statement->execute([$bookingId]);
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
    private function fetchTable(int $tableId): array|false
    {
        $statement = $this->pdo->prepare('SELECT * FROM tables WHERE id = ?');
        $statement->execute([$tableId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : false;
    }

    private function storageDateTime(string $iso): string
    {
        return Database::dateTimeFor($this->pdo, $iso);
    }
}

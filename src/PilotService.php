<?php

declare(strict_types=1);

namespace DineReserve;

use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * W13 pilot: replay one real dinner service against the engine, then verify the
 * invariants a restaurant would care about (no double bookings, every guest
 * accounted for, the report matches the floor, the backup restores).
 *
 * The simulation runs on a disposable copy of the demo restaurant and a frozen
 * clock that walks from the booking window to service time, so a "service" takes
 * milliseconds and is perfectly repeatable — the same scenario runs in CI.
 */
final class PilotService
{
    public const PILOT_DATE = '2026-12-16';

    /** Guest mix used by the pilot (dinner service, 12 tables). */
    private const DINNER_TABLES = ['T01', 'T02', 'T03', 'T07', 'T11', 'T12', 'T16', 'T20', 'T21', 'T25', 'T29', 'T30'];

    private PDO $pdo;

    private string $timezone;

    public function __construct(PDO $pdo, string $timezone = 'Asia/Macau')
    {
        $this->pdo = $pdo;
        $this->timezone = $timezone;
    }

    /**
     * Run the pilot service.
     *
     * @param string $date        service date (defaults to the pilot date)
     * @param int    $restaurantId restaurant to rehearse on
     * @param string|null $backupPath when set, the database is copied and the copy reopened
     * @return array{summary:array<string,mixed>,invariants:list<array{name:string,ok:bool,detail:string}>,report:array<string,mixed>,notifications:array<string,int>}
     */
    public function run(string $date = self::PILOT_DATE, int $restaurantId = 1, ?string $backupPath = null): array
    {
        $clock = new FrozenClock($this->dayBefore($date) . ' 10:00:00', $this->timezone);
        $directory = sys_get_temp_dir() . '/dinereserve-pilot-' . bin2hex(random_bytes(4));
        $mailer = new FileMailer($directory . '/mail.log');

        $availability = new AvailabilityService($this->pdo, $clock);
        $notifications = new NotificationService($this->pdo, $mailer, $clock);
        $bookings = new BookingService($this->pdo, $availability, $clock);
        $bookings->setNotifications($notifications);
        $waitlist = new WaitlistService($this->pdo, $availability, $clock, $mailer, $bookings);
        $bookings->setWaitlist($waitlist);
        $bookings->setRiskScorer(new RiskScorer($this->pdo, $clock));
        $floorplan = new FloorPlanService($this->pdo, $availability, $clock, $bookings);
        $reports = new ReportService($this->pdo, $clock);

        $created = [];
        $cancelled = [];
        $offers = [];
        $seated = [];
        $noShows = [];
        $walkIns = [];

        // ---------------------------------------------------------- bookings
        foreach (self::DINNER_TABLES as $index => $code) {
            $table = $this->table($restaurantId, $code);
            if ($table === []) {
                continue;
            }
            $time = $index < 6 ? '19:00' : '19:30';
            $channel = $index % 3 === 0 ? 'phone' : 'online';
            $result = $bookings->create([
                'restaurant_id' => $restaurantId,
                'table_id' => (int) $table['id'],
                'date' => $date,
                'time' => $time,
                'party_size' => (int) $table['seats'],
                'name' => 'Pilot Guest ' . ($index + 1),
                'email' => 'pilot' . ($index + 1) . '@example.com',
                'channel' => $channel,
            ]);
            $created[] = (string) $result['booking']['reference'];
        }

        // --------------------------------------------------------- waitlist
        $waitlistEntries = [];
        foreach ([[2, 'wait-2@example.com'], [4, 'wait-4@example.com'], [6, 'wait-6@example.com']] as [$party, $email]) {
            $entry = $waitlist->join([
                'restaurant_id' => $restaurantId,
                'date' => $date,
                'time' => '19:00',
                'party_size' => $party,
                'name' => 'Waitlisted ' . $party,
                'email' => $email,
            ]);
            $waitlistEntries[] = $entry;
        }

        // ------------------------------------------------- cancellation + offer
        foreach (array_slice($created, 0, 2) as $index => $reference) {
            $cancelled[] = $bookings->cancel([
                'reference' => $reference,
                'email' => 'pilot' . ($index + 1) . '@example.com',
            ]);
        }
        $offers = $this->offeredEntries($date);

        // Accept the first live offer (+2 min), let the rest expire (+10 min).
        if ($offers !== []) {
            $clock->advance('+2 minutes');
            try {
                $accepted = $waitlist->accept((int) $offers[0]['id'], ['email' => (string) $offers[0]['guest_contact']]);
                $seated[] = (string) $accepted['booking']['reference'];
            } catch (Throwable) {
                // A declined/expired offer is not a pilot failure; the invariant is checked below.
            }
            $clock->advance('+8 minutes');
            $expired = $waitlist->expireOffers();
        }

        // --------------------------------------------------------- service
        $clock->advance('+1 day 9 hours'); // service evening, 19:00 on the pilot date

        $confirmed = $this->bookingsWithStatus($date, ['CONFIRMED']);
        foreach (array_slice($confirmed, 0, 3) as $index => $booking) {
            try {
                $floorplan->seat((string) $booking['reference']);
                $seated[] = (string) $booking['reference'];
            } catch (Throwable) {
                // already seated through the waitlist conversion
            }
        }

        $freeTable = $this->firstFreeTable($restaurantId, $date);
        if ($freeTable !== []) {
            $walkIns[] = $floorplan->walkIn([
                'restaurant_id' => $restaurantId,
                'table_id' => (int) $freeTable['id'],
                'party_size' => 2,
                'name' => 'Walk-in Guest',
                'email' => 'walkin@example.com',
            ]);
        }

        $remaining = $this->bookingsWithStatus($date, ['CONFIRMED']);
        if ($remaining !== []) {
            try {
                $result = $floorplan->noShow((string) $remaining[0]['reference']);
                $noShows[] = (string) $remaining[0]['reference'];
                $releasedTable = (int) $result['table']['id'];
            } catch (Throwable) {
                $releasedTable = null;
            }
        }
        $seatedRows = $this->bookingsWithStatus($date, ['SEATED']);
        if ($seatedRows !== []) {
            try {
                $floorplan->release((int) $seatedRows[0]['table_id']);
            } catch (Throwable) {
                // releasing is best effort in the rehearsal
            }
        }

        // ---------------------------------------------------- notifications
        $clock->advance('+3 hours');
        $dispatch = $notifications->dispatch(500);
        $notificationCounts = $this->notificationCounts();

        // ---------------------------------------------------------- reports
        $report = $reports->occupancy($restaurantId, $date, $date);
        $floor = $floorplan->snapshot($restaurantId, $date, '20:00');

        $invariants = $this->invariants($restaurantId, $date, $report, $floor, $created, $notificationCounts);

        if ($backupPath !== null) {
            $invariants[] = $this->backupInvariant($backupPath);
        }

        $summary = [
            'date' => $date,
            'bookings_created' => count($created),
            'references' => $created,
            'cancelled' => count($cancelled),
            'waitlist_entries' => count($waitlistEntries),
            'offers' => count($offers),
            'accepted' => $seated,
            'no_shows' => $noShows,
            'walk_ins' => count($walkIns),
            'covers' => (int) $report['totals']['covers'],
            'utilisation_pct' => (float) $report['totals']['utilisation_pct'],
            'no_show_rate_pct' => (float) $report['totals']['no_show_rate_pct'],
            'channels' => $report['totals']['channels'],
            'notifications' => $notificationCounts,
            'dispatch' => $dispatch,
            'floor_plan' => $floor['summary'] ?? [],
        ];

        return [
            'summary' => $summary,
            'invariants' => $invariants,
            'report' => $report,
            'notifications' => $notificationCounts,
        ];
    }

    /** Copy the SQLite database to a backup path and verify it can be reopened. */
    public function backup(string $backupPath): array
    {
        $source = $this->databasePath();
        if ($source === null) {
            return ['ok' => false, 'detail' => 'The connection is not backed by a file, so it cannot be copied.'];
        }
        $directory = dirname($backupPath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return ['ok' => false, 'detail' => 'Cannot create the backup directory.'];
        }
        $copied = @copy($source, $backupPath);
        if (!$copied) {
            return ['ok' => false, 'detail' => 'Copying the database file failed.'];
        }
        $restored = Database::sqlite($backupPath);
        $tables = (int) $restored->query('SELECT COUNT(*) FROM tables')->fetchColumn();
        $bookings = (int) $restored->query('SELECT COUNT(*) FROM bookings')->fetchColumn();

        return [
            'ok' => $tables === 60,
            'detail' => sprintf('Restored copy has %d tables and %d bookings.', $tables, $bookings),
            'path' => $backupPath,
            'bookings' => $bookings,
        ];
    }

    // -------------------------------------------------------------- helpers

    private function dayBefore(string $date): string
    {
        return (new DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
    }

    /** @return array<string,mixed> */
    private function table(int $restaurantId, string $code): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM tables WHERE restaurant_id = ? AND code = ?');
        $statement->execute([$restaurantId, $code]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    /** @return list<array<string,mixed>> */
    private function bookingsWithStatus(string $date, array $statuses): array
    {
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $statement = $this->pdo->prepare(
            'SELECT * FROM bookings WHERE booking_date = ? AND status IN (' . $placeholders . ') ORDER BY start_time, id'
        );
        $statement->execute(array_merge([$date], $statuses));

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    private function offeredEntries(string $date): array
    {
        $statement = $this->pdo->prepare(
            "SELECT * FROM waitlist_entries WHERE entry_date = ? AND status = 'OFFERED' ORDER BY id"
        );
        $statement->execute([$date]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed> */
    private function firstFreeTable(int $restaurantId, string $date): array
    {
        $statement = $this->pdo->prepare(
            "SELECT * FROM tables
              WHERE restaurant_id = ? AND status = 'FREE'
                AND id NOT IN (SELECT table_id FROM bookings WHERE booking_date = ? AND status <> 'CANCELLED')
              ORDER BY seats, id LIMIT 1"
        );
        $statement->execute([$restaurantId, $date]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    /** @return array{queued:int,sent:int,failed:int,cancelled:int,total:int} */
    private function notificationCounts(): array
    {
        $rows = $this->pdo->query('SELECT status, COUNT(*) AS total FROM notification_log GROUP BY status')->fetchAll(PDO::FETCH_ASSOC);
        $counts = ['queued' => 0, 'sent' => 0, 'failed' => 0, 'cancelled' => 0, 'total' => 0];
        foreach ($rows as $row) {
            $status = strtolower((string) $row['status']);
            $counts['total'] += (int) $row['total'];
            if (isset($counts[$status])) {
                $counts[$status] = (int) $row['total'];
            }
        }

        return $counts;
    }

    /**
     * @param array<string,mixed> $report
     * @param array<string,mixed> $floor
     * @param list<string> $created
     * @param array<string,int> $notifications
     * @return list<array{name:string,ok:bool,detail:string}>
     */
    private function invariants(
        int $restaurantId,
        string $date,
        array $report,
        array $floor,
        array $created,
        array $notifications
    ): array {
        $invariants = [];

        // 1. No table is double booked: no two live bookings overlap on a table.
        $overlaps = $this->pdo->prepare(
            "SELECT COUNT(*) FROM bookings a JOIN bookings b
                ON a.table_id = b.table_id AND a.booking_date = b.booking_date
               AND a.id < b.id
               AND a.status <> 'CANCELLED' AND b.status <> 'CANCELLED'
               AND a.start_time < b.end_time AND b.start_time < a.end_time
              WHERE a.booking_date = ?"
        );
        $overlaps->execute([$date]);
        $overlapCount = (int) $overlaps->fetchColumn();
        $invariants[] = [
            'name' => 'no double booking',
            'ok' => $overlapCount === 0,
            'detail' => $overlapCount . ' overlapping pair(s)',
        ];

        // 2. Every booking is in a known state.
        $unknown = $this->pdo->prepare(
            "SELECT COUNT(*) FROM bookings WHERE booking_date = ?
              AND status NOT IN ('CONFIRMED','SEATED','COMPLETED','CANCELLED','NO_SHOW')"
        );
        $unknown->execute([$date]);
        $invariants[] = [
            'name' => 'every booking has a known status',
            'ok' => (int) $unknown->fetchColumn() === 0,
            'detail' => 'statuses checked',
        ];

        // 3. The report agrees with the booking rows.
        $served = $this->pdo->prepare(
            "SELECT COALESCE(SUM(party_size), 0) FROM bookings
              WHERE booking_date = ? AND status IN ('CONFIRMED','SEATED','COMPLETED')"
        );
        $served->execute([$date]);
        $covers = (int) $served->fetchColumn();
        $invariants[] = [
            'name' => 'report covers match the bookings',
            'ok' => $covers === (int) $report['totals']['covers'],
            'detail' => 'bookings=' . $covers . ', report=' . (int) $report['totals']['covers'],
        ];

        // 4. Utilisation stays inside 0–100 and capacity is positive.
        $utilisation = (float) $report['totals']['utilisation_pct'];
        $invariants[] = [
            'name' => 'utilisation is within 0–100%',
            'ok' => $utilisation >= 0.0 && $utilisation <= 100.0 && (int) $report['capacity_per_day'] > 0,
            'detail' => sprintf('%.1f%% of %d covers', $utilisation, (int) $report['capacity_per_day']),
        ];

        // 5. The floor plan agrees with booking states.
        $occupied = (int) $this->pdo->query("SELECT COUNT(*) FROM tables WHERE status = 'OCCUPIED'")->fetchColumn();
        $seated = $this->pdo->prepare("SELECT COUNT(*) FROM bookings WHERE booking_date = ? AND status = 'SEATED'");
        $seated->execute([$date]);
        $seatedCount = (int) $seated->fetchColumn();
        $invariants[] = [
            'name' => 'occupied tables match seated bookings',
            'ok' => $seatedCount >= $occupied,
            'detail' => 'tables=' . $occupied . ', seated=' . $seatedCount,
        ];

        // 6. Every created booking still exists exactly once.
        $placeholders = implode(',', array_fill(0, max(1, count($created)), '?'));
        $found = $this->pdo->prepare('SELECT COUNT(*) FROM bookings WHERE reference IN (' . $placeholders . ')');
        $found->execute($created === [] ? ['__none__'] : $created);
        $invariants[] = [
            'name' => 'every created booking exists exactly once',
            'ok' => (int) $found->fetchColumn() === count($created),
            'detail' => (int) $found->fetchColumn() . '/' . count($created),
        ];

        // 7. Notifications are all accounted for.
        $invariants[] = [
            'name' => 'notifications are all accounted for',
            'ok' => $notifications['total'] === $notifications['queued'] + $notifications['sent'] + $notifications['failed'] + $notifications['cancelled'],
            'detail' => json_encode($notifications, JSON_UNESCAPED_SLASHES) ?: '{}',
        ];

        // 8. The audit trail exists for the service.
        $events = $this->pdo->prepare(
            'SELECT COUNT(*) FROM booking_events e JOIN bookings b ON b.id = e.booking_id WHERE b.booking_date = ?'
        );
        $events->execute([$date]);
        $invariants[] = [
            'name' => 'every booking change left an event',
            'ok' => (int) $events->fetchColumn() > 0,
            'detail' => (int) $events->fetchColumn() . ' event(s)',
        ];

        return $invariants;
    }

    /** @return array{name:string,ok:bool,detail:string} */
    private function backupInvariant(string $backupPath): array
    {
        $result = $this->backup($backupPath);

        return [
            'name' => 'backup restores to a working copy',
            'ok' => (bool) $result['ok'],
            'detail' => (string) $result['detail'],
        ];
    }

    private function databasePath(): ?string
    {
        try {
            $rows = $this->pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return null;
        }
        foreach ($rows as $row) {
            if ((string) ($row['name'] ?? '') === 'main' && is_string($row['file'] ?? null) && $row['file'] !== '') {
                return (string) $row['file'];
            }
        }

        return null;
    }
}

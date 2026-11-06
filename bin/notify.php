<?php

declare(strict_types=1);

/**
 * Deliver due DineReserve notifications (REQ-1.4).
 *
 * Usage:
 *   php bin/notify.php                     # deliver everything that is due
 *   php bin/notify.php --limit=100         # cap the batch size
 *   php bin/notify.php --dry-run           # only list what would be sent
 *   php bin/notify.php database/local.sqlite
 *
 * Wire it to cron (or a scheduler) so the 24 h / 2 h reminders actually leave
 * the queue, e.g.:
 *   * * * * * cd /srv/dinereserve && php bin/notify.php --limit=200
 *
 * Delivery is simulated by FileMailer (storage/mail.log) unless a real Mailer
 * adapter is bound in the TastyIgniter build.
 */

require dirname(__DIR__) . '/bootstrap/autoload.php';

use DineReserve\AvailabilityService;
use DineReserve\BookingService;
use DineReserve\Database;
use DineReserve\FileMailer;
use DineReserve\NotificationService;
use DineReserve\SystemClock;

$root = dirname(__DIR__);
$options = ['limit' => 50, 'dry-run' => false, 'db' => null];

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--limit=')) {
        $options['limit'] = max(1, (int) substr($argument, 8));
    } elseif ($argument === '--dry-run') {
        $options['dry-run'] = true;
    } elseif (!str_starts_with($argument, '--')) {
        $options['db'] = $argument;
    }
}

$path = $options['db']
    ?? (getenv('DB_PATH') ?: $root . '/database/local.sqlite');
if ($path !== ':memory:' && !str_starts_with($path, '/')) {
    $path = $root . '/' . $path;
}

if ($path !== ':memory:' && !is_file($path)) {
    fwrite(STDERR, "Database not found: {$path}\nRun: php bin/seed.php\n");
    exit(1);
}

$clock = new SystemClock((string) (getenv('RESTAURANT_TIMEZONE') ?: 'Asia/Macau'));
$pdo = Database::sqlite($path);
$bookings = new BookingService($pdo, new AvailabilityService($pdo, $clock), $clock);
$notifications = new NotificationService(
    $pdo,
    new FileMailer((string) (getenv('MAIL_LOG_PATH') ?: $root . '/storage/mail.log')),
    $clock,
    static fn (string $reference): string => $bookings->issueManageToken($reference)
);

if ($options['dry-run']) {
    $due = $notifications->due($options['limit']);
    printf("Due notifications: %d\n", count($due));
    foreach ($due as $row) {
        printf(
            "  #%d  booking %d  %-24s  scheduled %s\n",
            $row['id'],
            $row['booking_id'],
            $row['template'],
            (string) $row['scheduled_at']
        );
    }
    exit(0);
}

$summary = $notifications->dispatch($options['limit']);
printf(
    "Due: %d | sent: %d | retried: %d | failed: %d | cancelled: %d\n",
    $summary['due'],
    $summary['sent'],
    $summary['retried'],
    $summary['failed'],
    $summary['cancelled']
);

exit($summary['failed'] > 0 ? 1 : 0);

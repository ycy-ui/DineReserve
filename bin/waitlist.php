<?php

declare(strict_types=1);

/**
 * Waitlist maintenance for DineReserve (REQ-1.5).
 *
 * Usage:
 *   php bin/waitlist.php --expire              # expire 5-minute offers, re-offer
 *   php bin/waitlist.php --expire --dry-run    # only list what would expire
 *   php bin/waitlist.php database/local.sqlite
 *
 * Wire the expiry sweep to cron next to the notification dispatcher:
 *   * * * * * cd /srv/dinereserve && php bin/waitlist.php --expire
 */

require dirname(__DIR__) . '/bootstrap/autoload.php';

use DineReserve\AvailabilityService;
use DineReserve\BookingService;
use DineReserve\Database;
use DineReserve\FileMailer;
use DineReserve\NotificationService;
use DineReserve\SystemClock;
use DineReserve\WaitlistService;

$root = dirname(__DIR__);
$options = ['expire' => false, 'dry-run' => false, 'db' => null];

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--expire') {
        $options['expire'] = true;
    } elseif ($argument === '--dry-run') {
        $options['dry-run'] = true;
    } elseif (!str_starts_with($argument, '--')) {
        $options['db'] = $argument;
    }
}

$path = $options['db'] ?? (getenv('DB_PATH') ?: $root . '/database/local.sqlite');
if ($path !== ':memory:' && !str_starts_with($path, '/')) {
    $path = $root . '/' . $path;
}
if ($path !== ':memory:' && !is_file($path)) {
    fwrite(STDERR, "Database not found: {$path}\nRun: php bin/seed.php\n");
    exit(1);
}

$clock = new SystemClock((string) (getenv('RESTAURANT_TIMEZONE') ?: 'Asia/Macau'));
$pdo = Database::sqlite($path);
$availability = new AvailabilityService($pdo, $clock);
$bookings = new BookingService($pdo, $availability, $clock);
$mailer = new FileMailer((string) (getenv('MAIL_LOG_PATH') ?: $root . '/storage/mail.log'));
$notifications = new NotificationService($pdo, $mailer, $clock, static fn (string $ref): string => $bookings->issueManageToken($ref));
$bookings->setNotifications($notifications);
$waitlist = new WaitlistService($pdo, $availability, $clock, $mailer, $bookings);
$bookings->setWaitlist($waitlist);

if ($options['dry-run']) {
    $now = Database::dateTimeFor($pdo, \DineReserve\Slot::iso8601($clock->now()));
    $statement = $pdo->prepare(
        "SELECT id, entry_date, offer_time, offer_table_id FROM waitlist_entries
          WHERE status = 'OFFERED' AND offer_expires_at IS NOT NULL AND offer_expires_at <= ?
          ORDER BY offer_expires_at, id"
    );
    $statement->execute([$now]);
    $rows = $statement->fetchAll();
    printf("Expired offers: %d\n", count($rows));
    foreach ($rows as $row) {
        printf("  #%d  %s %s  table %d\n", $row['id'], $row['entry_date'], (string) $row['offer_time'], (int) $row['offer_table_id']);
    }
    exit(0);
}

if (!$options['expire']) {
    fwrite(STDERR, "Nothing to do. Pass --expire to sweep the expired offers.\n");
    exit(1);
}

$summary = $waitlist->expireOffers();
printf("Expired: %d | re-offered: %d\n", $summary['expired'], $summary['offered']);
exit(0);

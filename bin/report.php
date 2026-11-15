<?php

declare(strict_types=1);

/**
 * Reports and risk scoring for DineReserve (REQ-1.7 / REQ-1.8).
 *
 * Usage:
 *   php bin/report.php --from=2026-11-01 --to=2026-11-30
 *   php bin/report.php --from=… --to=… --format=xlsx --out=/tmp/report.xlsx
 *   php bin/report.php --from=… --to=… --format=pdf  --out=/tmp/report.pdf
 *   php bin/report.php --rescore --from=2026-11-01 --to=2026-11-30
 *
 * Without --format the report is printed as a plain-text table to stdout.
 */

require dirname(__DIR__) . '/bootstrap/autoload.php';

use DineReserve\Database;
use DineReserve\ReportExporter;
use DineReserve\ReportService;
use DineReserve\RiskScorer;
use DineReserve\SystemClock;

$root = dirname(__DIR__);
$options = ['from' => null, 'to' => null, 'format' => 'table', 'out' => null, 'rescore' => false, 'db' => null, 'restaurant' => 1];

foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--(from|to|format|out|restaurant|db)=(.*)$/', $argument, $match) === 1) {
        $options[$match[1]] = $match[2];
    } elseif ($argument === '--rescore') {
        $options['rescore'] = true;
    }
}
if ($options['db'] === null) {
    $options['db'] = (string) (getenv('DB_PATH') ?: $root . '/database/local.sqlite');
}
$path = $options['db'];
if ($path !== ':memory:' && !str_starts_with($path, '/')) {
    $path = $root . '/' . $path;
}
if ($path !== ':memory:' && !is_file($path)) {
    fwrite(STDERR, "Database not found: {$path}\nRun: php bin/seed.php\n");
    exit(1);
}

$clock = new SystemClock((string) (getenv('RESTAURANT_TIMEZONE') ?: 'Asia/Macau'));
$pdo = Database::sqlite($path);
$reports = new ReportService($pdo, $clock);
$scorer = new RiskScorer($pdo, $clock);

if ($options['rescore']) {
    $from = (string) ($options['from'] ?? (new DateTimeImmutable('first day of last month'))->format('Y-m-d'));
    $to = (string) ($options['to'] ?? (new DateTimeImmutable('last day of this month'))->format('Y-m-d'));
    $count = $scorer->rescoreRange($from, $to, (int) $options['restaurant']);
    printf("Re-scored %d bookings between %s and %s (rules %s).\n", $count, $from, $to, RiskScorer::RULE_VERSION);
    exit(0);
}

$from = (string) ($options['from'] ?? (new DateTimeImmutable('first day of this month'))->format('Y-m-d'));
$to = (string) ($options['to'] ?? (new DateTimeImmutable('last day of this month'))->format('Y-m-d'));
$report = $reports->occupancy((int) $options['restaurant'], $from, $to);

if ($options['format'] === 'table') {
    printf("%s  (%s)\n", $report['restaurant']['name'], $report['from'] . ' → ' . $report['to']);
    printf("Capacity per day: %d covers\n\n", $report['capacity_per_day']);
    printf("%-12s %7s %9s %6s %8s %8s %8s %8s\n", 'Date', 'Covers', 'Capacity', 'Util%', 'Booked', 'NoShow', 'Cancel', 'Rate%');
    foreach ($report['days'] as $day) {
        printf(
            "%-12s %7d %9d %6.1f %8d %8d %8d %8.1f\n",
            $day['date'],
            $day['covers'],
            $day['capacity'],
            $day['utilisation_pct'],
            $day['bookings'],
            $day['no_shows'],
            $day['cancellations'],
            $day['no_show_rate_pct']
        );
    }
    $totals = $report['totals'];
    printf(
        "%-12s %7d %9d %6.1f %8d %8d %8d %8.1f\n",
        'TOTAL',
        $totals['covers'],
        $totals['capacity'],
        $totals['utilisation_pct'],
        $totals['bookings'],
        $totals['no_shows'],
        $totals['cancellations'],
        $totals['no_show_rate_pct']
    );
    exit(0);
}

$extension = $options['format'];
$out = (string) ($options['out'] ?: $root . '/storage/report-' . $from . '-to-' . $to . '.' . $extension);
$exporter = new ReportExporter();
$written = $exporter->export($report, $options['format'], $out);
printf("Wrote %s (%d bytes)\n", $written, filesize($written) ?: 0);

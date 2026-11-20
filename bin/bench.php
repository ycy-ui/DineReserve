<?php

declare(strict_types=1);

/**
 * Performance benchmark for the non-functional requirements (W12).
 *
 *   php bin/bench.php [--iterations=100]
 *
 * Measures the hot paths on an in-memory SQLite database seeded with the demo
 * restaurant (60 tables, 3 service periods) and fails when a p95 threshold is
 * missed, so the NFR can be checked in CI as well as on a laptop.
 */

require dirname(__DIR__) . '/bootstrap/autoload.php';

use DineReserve\AvailabilityService;
use DineReserve\BookingService;
use DineReserve\Clock;
use DineReserve\Database;
use DineReserve\FloorPlanService;
use DineReserve\FrozenClock;
use DineReserve\ReportService;

$iterations = 100;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--iterations=(\d+)$/', $argument, $match) === 1) {
        $iterations = max(10, min(1000, (int) $match[1]));
    }
}

$clock = new FrozenClock('2026-11-02 10:00:00');
$pdo = Database::sqlite(':memory:');
Database::migrate($pdo);
$seed = Database::seedDemo($pdo, 60);
$restaurantId = $seed['restaurant_id'];

$availability = new AvailabilityService($pdo, $clock);
$bookings = new BookingService($pdo, $availability, $clock);

/** @param callable():void $operation */
function measure(callable $operation, int $iterations): array
{
    $samples = [];
    for ($i = 0; $i < $iterations; $i++) {
        $start = hrtime(true);
        $operation($i);
        $samples[] = (hrtime(true) - $start) / 1e6;
    }
    sort($samples);
    $count = count($samples);
    $mean = array_sum($samples) / $count;

    return [
        'p50' => $samples[(int) floor($count * 0.5)] ?? 0.0,
        'p95' => $samples[(int) floor($count * 0.95)] ?? $samples[$count - 1],
        'mean' => $mean,
        'max' => $samples[$count - 1],
    ];
}

$results = [];
$results['availability search'] = measure(
    fn () => $availability->search($restaurantId, '2026-11-10', '19:00', 4),
    $iterations
);
$bookableTables = array_map(
    static fn (array $row): int => (int) $row['id'],
    $pdo->query("SELECT id FROM tables WHERE restaurant_id = {$restaurantId} AND status = 'FREE' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC)
);
if ($bookableTables === []) {
    $bookableTables = $seed['tables'];
}

$results['booking creation'] = measure(
    fn (int $i) => $bookings->create([
        'restaurant_id' => $restaurantId,
        'table_id' => $bookableTables[$i % count($bookableTables)],
        'date' => '2026-11-' . str_pad((string) (10 + $i % 10), 2, '0', STR_PAD_LEFT),
        'time' => '19:00',
        'party_size' => 2,
        'name' => 'Bench Guest',
        'email' => 'bench' . $i . '@example.com',
        'channel' => 'phone',
    ]),
    max(10, (int) ($iterations / 2))
);
$results['report generation'] = measure(
    fn () => (new ReportService($pdo, $clock))->occupancy($restaurantId, '2026-11-01', '2026-11-30'),
    max(10, (int) ($iterations / 5))
);
$floorplan = new FloorPlanService($pdo, $availability, $clock, $bookings);
$results['floor plan snapshot'] = measure(
    fn () => $floorplan->snapshot($restaurantId, '2026-11-10', '19:00'),
    max(10, (int) ($iterations / 5))
);

$thresholds = [
    'availability search' => 250.0,
    'booking creation' => 400.0,
    'report generation' => 500.0,
    'floor plan snapshot' => 300.0,
];

printf("%-24s %10s %10s %10s %10s %10s\n", 'Operation', 'p50 ms', 'p95 ms', 'mean ms', 'max ms', 'budget');
$failures = [];
foreach ($results as $name => $metrics) {
    $budget = $thresholds[$name] ?? 0.0;
    printf(
        "%-24s %10.2f %10.2f %10.2f %10.2f %10.0f\n",
        $name,
        $metrics['p50'],
        $metrics['p95'],
        $metrics['mean'],
        $metrics['max'],
        $budget
    );
    if ($metrics['p95'] > $budget) {
        $failures[] = sprintf('%s p95 %.2f ms > %.0f ms', $name, $metrics['p95'], $budget);
    }
}

if ($failures !== []) {
    fwrite(STDERR, "NFR performance budget missed:\n  - " . implode("\n  - ", $failures) . "\n");
    exit(1);
}

echo "NFR performance budget met (SQLite in-memory, 60 tables).\n";

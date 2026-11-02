<?php

declare(strict_types=1);

/**
 * DineReserve HTTP API (W1–W6 scope: health, restaurant profile,
 * availability search REQ-1.1, booking creation REQ-1.2, booking lookup).
 *
 * Run locally:  php bin/seed.php && php -S 127.0.0.1:8080 -t public
 * The same code runs in CI (see .github/workflows/ci.yml) and targets the
 * MySQL 8.0 deployment of the TastyIgniter build through DB_DSN/DB_* env vars.
 */

use DineReserve\AvailabilityService;
use DineReserve\BookingService;
use DineReserve\Database;
use DineReserve\DomainException;
use DineReserve\SystemClock;

$root = dirname(__DIR__);

require $root . '/bootstrap/autoload.php';

/** @var PDO $pdo */
try {
    $pdo = (static function () use ($root): PDO {
        $mysqlHost = getenv('DB_HOST');
        if ($mysqlHost !== false && $mysqlHost !== '') {
            return Database::mysql(
                $mysqlHost,
                getenv('DB_DATABASE') ?: 'dinereserve',
                getenv('DB_USERNAME') ?: 'root',
                getenv('DB_PASSWORD') ?: '',
                (int) (getenv('DB_PORT') ?: 3306)
            );
        }

        // The PHP built-in server switches its working directory to the document
        // root, so a relative DB_PATH must be resolved against the repository root.
        $path = (string) (getenv('DB_PATH') ?: $root . '/database/local.sqlite');
        if ($path !== ':memory:' && !str_starts_with($path, '/')) {
            $path = $root . '/' . $path;
        }

        $pdo = Database::sqlite($path);
        $hasSchema = (bool) $pdo
            ->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'restaurants'")
            ->fetchColumn();
        if (!$hasSchema) {
            Database::migrate($pdo);
        }
        if (Database::isEmpty($pdo)) {
            Database::seedDemo($pdo);
        }

        return $pdo;
    })();
} catch (Throwable $exception) {
    error_log('DineReserve database startup failure: ' . $exception->getMessage());
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(503);
    echo json_encode([
        'error' => [
            'code' => 'DATABASE_UNAVAILABLE',
            'message' => 'The booking service is temporarily unavailable. Please retry shortly.',
            'details' => [],
        ],
    ], JSON_PRETTY_PRINT), "\n";
    exit;
}

$clock = new SystemClock((string) (getenv('RESTAURANT_TIMEZONE') ?: 'Asia/Macau'));
$availability = new AvailabilityService($pdo, $clock);
$bookings = new BookingService($pdo, $availability, $clock);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$path = rtrim($path, '/') ?: '/';

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/**
 * @param mixed $data
 */
function respond($data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
    exit;
}

/**
 * @param mixed $data
 */
function respondError(int $status, string $code, string $message, array $details = []): void
{
    respond(['error' => ['code' => $code, 'message' => $message, 'details' => $details]], $status);
}

/** @return array<string,mixed> */
function bookingPayload(PDO $pdo, array $row): array
{
    $tableStmt = $pdo->prepare('SELECT id, code, seats, area FROM tables WHERE id = ?');
    $tableStmt->execute([$row['table_id']]);
    $table = $tableStmt->fetch() ?: ['id' => $row['table_id'], 'code' => '?', 'seats' => 0, 'area' => '?'];

    $start = new DateTimeImmutable($row['booking_date'] . ' ' . $row['start_time'], new DateTimeZone('Asia/Macau'));

    return [
        'booking' => [
            'id' => (int) $row['id'],
            'reference' => $row['reference'],
            'status' => $row['status'],
            'date' => $row['booking_date'],
            'start' => $row['start_time'],
            'end' => $row['end_time'],
            'party_size' => (int) $row['party_size'],
            'table' => [
                'id' => (int) $table['id'],
                'code' => $table['code'],
                'seats' => (int) $table['seats'],
                'area' => $table['area'],
            ],
            'guest' => [
                'name' => $row['guest_name'],
                'email' => $row['guest_email'],
                'mobile' => $row['guest_mobile'],
            ],
            'special_requests' => $row['special_requests'],
            'cutoff_at' => $row['cutoff_at'],
            'reminders' => [
                $start->modify('-24 hours')->format('Y-m-d\TH:i:sP'),
                $start->modify('-2 hours')->format('Y-m-d\TH:i:sP'),
            ],
            'channel' => $row['channel'],
            'created_at' => $row['created_at'],
        ],
    ];
}

try {
    switch (true) {
        case $path === '/' || $path === '/api':
            respond([
                'name' => 'DineReserve API',
                'version' => '0.1.0',
                'description' => 'Availability search (REQ-1.1) and booking creation (REQ-1.2) for DineReserve.',
                'endpoints' => [
                    'GET /api/v1/health',
                    'GET /api/v1/restaurant',
                    'GET /api/v1/availability?restaurant_id=1&date=YYYY-MM-DD&time=HH:MM&party_size=N[&area=main]',
                    'POST /api/v1/bookings',
                    'GET /api/v1/bookings/{reference}?email=...',
                ],
                'documentation' => 'docs/03-api.md',
            ]);

        case $path === '/api/v1/health':
            respond([
                'status' => 'ok',
                'php' => PHP_VERSION,
                'database' => Database::driver($pdo),
                'time' => $clock->now()->format('Y-m-d\TH:i:sP'),
            ]);

        case $path === '/api/v1/restaurant':
            $restaurant = $pdo->query('SELECT * FROM restaurants ORDER BY id LIMIT 1')->fetch();
            if ($restaurant === false) {
                throw DomainException::notFound('No restaurant has been configured yet.');
            }
            $periods = $pdo->prepare(
                'SELECT id, name, start_time, end_time, avg_dining_minutes
                 FROM service_periods WHERE restaurant_id = ? ORDER BY start_time'
            );
            $periods->execute([$restaurant['id']]);
            $tableCount = $pdo->prepare('SELECT COUNT(*) FROM tables WHERE restaurant_id = ?');
            $tableCount->execute([$restaurant['id']]);

            respond([
                'restaurant' => [
                    'id' => (int) $restaurant['id'],
                    'name' => $restaurant['name'],
                    'address' => $restaurant['address'],
                    'timezone' => $restaurant['timezone'],
                    'currency' => $restaurant['currency'],
                    'languages' => $restaurant['languages'],
                ],
                'service_periods' => array_map(static fn (array $p): array => [
                    'id' => (int) $p['id'],
                    'name' => $p['name'],
                    'start' => $p['start_time'],
                    'end' => $p['end_time'],
                    'avg_dining_minutes' => (int) $p['avg_dining_minutes'],
                ], $periods->fetchAll()),
                'tables' => (int) $tableCount->fetchColumn(),
            ]);

        case $path === '/api/v1/availability':
            $restaurantId = (int) ($_GET['restaurant_id'] ?? 1);
            $date = (string) ($_GET['date'] ?? '');
            $time = (string) ($_GET['time'] ?? '');
            $partySize = (int) ($_GET['party_size'] ?? 0);
            $filters = [];
            if (isset($_GET['area']) && $_GET['area'] !== '') {
                $filters['area'] = (string) $_GET['area'];
            }
            if (isset($_GET['service_period']) && $_GET['service_period'] !== '') {
                $filters['service_period'] = (string) $_GET['service_period'];
            }

            respond($availability->search($restaurantId, $date, $time, $partySize, $filters));

        case $path === '/api/v1/bookings' && $method === 'POST':
            $raw = file_get_contents('php://input') ?: '';
            $input = json_decode($raw, true);
            if (!is_array($input)) {
                respondError(400, 'MALFORMED_JSON', 'The request body must be a JSON object.');
            }
            $result = $bookings->create($input);
            respond($result, $result['created'] ? 201 : 200);

        case preg_match('#^/api/v1/bookings/([A-Z2-9]{8})$#', $path, $matches) === 1 && $method === 'GET':
            $stmt = $pdo->prepare('SELECT * FROM bookings WHERE reference = ?');
            $stmt->execute([$matches[1]]);
            $row = $stmt->fetch();
            $email = strtolower(trim((string) ($_GET['email'] ?? '')));
            // A booking may only be read by someone who presents the reference
            // AND the e-mail used at booking time (URS SEC-1.6).
            if ($row === false || $email === '' || strtolower($row['guest_email']) !== $email) {
                throw DomainException::notFound('Booking not found.');
            }
            respond(bookingPayload($pdo, $row));

        default:
            throw new DomainException('ROUTE_NOT_FOUND', sprintf('No route for %s %s.', $method, $path), [], 404);
    }
} catch (DomainException $exception) {
    respondError($exception->status(), $exception->errorCode(), $exception->getMessage(), $exception->details());
} catch (PDOException $exception) {
    error_log('DineReserve database failure: ' . $exception->getMessage());
    respondError(503, 'DATABASE_UNAVAILABLE', 'The booking service is temporarily unavailable. Please retry shortly.');
} catch (Throwable $exception) {
    error_log('DineReserve failure: ' . $exception->getMessage());
    respondError(500, 'INTERNAL_ERROR', 'Unexpected server error.');
}

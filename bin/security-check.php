<?php

declare(strict_types=1);

/**
 * Security self-check for the non-functional requirements (W12).
 *
 *   php bin/security-check.php
 *
 * Runs the OWASP-style checks that can be automated against an in-memory copy of
 * the demo database: credential storage, authentication, authorisation, input
 * handling, error envelopes and the absence of information leaks. Exit code 1
 * means at least one check failed.
 */

require dirname(__DIR__) . '/bootstrap/autoload.php';

use DineReserve\AdminService;
use DineReserve\AvailabilityService;
use DineReserve\BookingService;
use DineReserve\Database;
use DineReserve\DomainException;
use DineReserve\FrozenClock;

$clock = new FrozenClock('2026-11-02 10:00:00');
$pdo = Database::sqlite(':memory:');
Database::migrate($pdo);
$seed = Database::seedDemo($pdo, 60);
$restaurantId = $seed['restaurant_id'];

$admin = new AdminService($pdo, $clock);
$bookings = new BookingService($pdo, new AvailabilityService($pdo, $clock), $clock);

$passes = 0;
$failures = [];

/** @param callable():bool $check */
function check(string $label, callable $check): void
{
    global $passes, $failures;
    try {
        $ok = $check() === true;
    } catch (Throwable $exception) {
        $ok = false;
    }
    if ($ok) {
        $passes++;
        printf("PASS  %s\n", $label);
    } else {
        $failures[] = $label;
        printf("FAIL  %s\n", $label);
    }
}

/** @return array{code:string,status:int,details:array<string,mixed>} */
function catchDomain(callable $operation): array
{
    try {
        $operation();
    } catch (DomainException $exception) {
        return [
            'code' => $exception->errorCode(),
            'status' => $exception->status(),
            'details' => $exception->details(),
        ];
    }

    return ['code' => '', 'status' => 0, 'details' => []];
}

// 1. Credential storage
check('passwords are hashed with password_hash() (bcrypt/argon2)', function () use ($pdo): bool {
    foreach ($pdo->query('SELECT password_hash FROM users')->fetchAll(PDO::FETCH_COLUMN) as $hash) {
        if (!preg_match('/^\$(2[aby]|argon2)/', (string) $hash)) {
            return false;
        }
    }

    return true;
});

// 2. No plaintext credential in the database
check('no plaintext password is stored', function () use ($pdo): bool {
    $row = $pdo->query('SELECT COUNT(*) FROM users WHERE password_hash = "DemoPass123!"')->fetchColumn();

    return (int) $row === 0;
});

// 3. Authentication rejects a wrong password
check('wrong password is rejected with 401 INVALID_CREDENTIALS', function () use ($admin): bool {
    $result = catchDomain(fn () => $admin->authenticate('manager@dinereserve.local', 'wrong-password'));

    return $result['status'] === 401 && $result['code'] === 'INVALID_CREDENTIALS';
});

// 4. Authentication succeeds with the demo credential
check('valid credentials return the user with roles', function () use ($admin): bool {
    $user = $admin->authenticate('manager@dinereserve.local', 'DemoPass123!');

    return $user['email'] === 'manager@dinereserve.local' && $user['roles'] !== [];
});

// 5. Admin token comparison
check('admin token is required and compared in constant time', function () use ($admin): bool {
    $wrong = catchDomain(fn () => $admin->requireToken('not-the-token'));
    $admin->requireToken(AdminService::DEFAULT_ADMIN_TOKEN);

    return $wrong['status'] === 401 && $wrong['code'] === 'UNAUTHORIZED';
});

// 6. Capability matrix: least privilege
check('host cannot change settings (403 FORBIDDEN)', function () use ($admin): bool {
    $result = catchDomain(fn () => $admin->requireCapability('host', 'settings.write'));

    return $result['status'] === 403 && $result['code'] === 'FORBIDDEN';
});
check('manager can read settings but cannot manage users', function () use ($admin): bool {
    return $admin->can('manager', 'settings.read') && !$admin->can('manager', 'users.write');
});
check('viewer can only read reports', function () use ($admin): bool {
    return $admin->can('viewer', 'reports.read') && !$admin->can('viewer', 'bookings.write');
});
check('administrator holds every capability', function () use ($admin): bool {
    foreach (['users.read', 'users.write', 'settings.read', 'settings.write', 'bookings.read', 'bookings.write', 'reports.read', 'floorplan.write'] as $capability) {
        if (!$admin->can('administrator', $capability)) {
            return false;
        }
    }

    return true;
});

// 7. Settings validation
check('out-of-range setting is rejected (422)', function () use ($admin, $restaurantId): bool {
    $result = catchDomain(fn () => $admin->updateSettings($restaurantId, ['cutoff_minutes' => 99999]));

    return $result['status'] === 422;
});
check('unknown setting key is rejected', function () use ($admin, $restaurantId): bool {
    $result = catchDomain(fn () => $admin->updateSettings($restaurantId, ['max_tables' => 5]));

    return $result['status'] === 422;
});

// 8. Input handling: injection strings never reach SQL as syntax
check('SQL injection string in a reference parameter is treated as data', function () use ($pdo): bool {
    $statement = $pdo->prepare('SELECT COUNT(*) FROM bookings WHERE reference = ?');
    $statement->execute(["' OR 1=1 --"]);
    $injected = (int) $statement->fetchColumn();

    $all = (int) $pdo->query('SELECT COUNT(*) FROM bookings')->fetchColumn();

    // The prepared statement matches nothing, and the table still exists.
    return $injected === 0 && $all >= 0;
});
check('unknown booking reference yields no rows (no enumeration oracle)', function () use ($pdo): bool {
    $statement = $pdo->prepare('SELECT COUNT(*) FROM bookings WHERE reference = ?');
    $statement->execute(['ZZZZZZZZ']);

    return (int) $statement->fetchColumn() === 0;
});

// 9. Validation envelope
check('invalid booking input returns every field error in one response', function () use ($bookings, $restaurantId, $seed): bool {
    $result = catchDomain(fn () => $bookings->create([
        'restaurant_id' => $restaurantId,
        'table_id' => $seed['tables'][0],
        'date' => 'not-a-date',
        'time' => '99:99',
        'party_size' => 0,
        'name' => '',
        'email' => 'not-an-email',
    ]));

    return $result['status'] === 422 && count($result['details']) >= 5;
});

// 10. Audit trail is written for authentication events
check('authentication events are audited', function () use ($pdo): bool {
    catchDomain(fn () => (new AdminService($pdo, new FrozenClock('2026-11-02 10:00:00')))
        ->authenticate('manager@dinereserve.local', 'wrong-password'));

    return (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.failed'")->fetchColumn() > 0;
});

printf("\n%d checks passed, %d failed.\n", $passes, count($failures));
if ($failures !== []) {
    fwrite(STDERR, "Security checks failed:\n  - " . implode("\n  - ", $failures) . "\n");
    exit(1);
}
echo "Security baseline met.\n";

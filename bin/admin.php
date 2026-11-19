<?php

declare(strict_types=1);

/**
 * Administration CLI for DineReserve (REQ-1.9, W12).
 *
 *   php bin/admin.php list
 *   php bin/admin.php create --name="Lee Host" --email=lee@example.com --password=Passw0rd! --role=host
 *   php bin/admin.php set-role --id=2 --role=manager
 *   php bin/admin.php settings --restaurant=1
 *   php bin/admin.php settings --restaurant=1 --set=cutoff_minutes=90 --set=slot_minutes=30
 */

require dirname(__DIR__) . '/bootstrap/autoload.php';

use DineReserve\AdminService;
use DineReserve\Database;
use DineReserve\SystemClock;

$root = dirname(__DIR__);
$command = $argv[1] ?? 'list';
$options = ['db' => null, 'restaurant' => 1, 'set' => []];

foreach (array_slice($argv, 2) as $argument) {
    if (preg_match('/^--(name|email|password|role|id|db|restaurant|set)=(.*)$/', $argument, $match) === 1) {
        if ($match[1] === 'set') {
            $options['set'][] = $match[2];
        } else {
            $options[$match[1]] = $match[2];
        }
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
$admin = new AdminService($pdo, $clock);

try {
    switch ($command) {
        case 'list':
            printf("%-4s %-20s %-32s %-6s %s\n", 'ID', 'Name', 'E-mail', 'Active', 'Roles');
            foreach ($admin->users() as $user) {
                printf(
                    "%-4d %-20s %-32s %-6s %s\n",
                    $user['id'],
                    $user['name'],
                    $user['email'],
                    $user['active'] ? 'yes' : 'no',
                    implode(',', $user['roles'])
                );
            }
            break;

        case 'create':
            $user = $admin->createUser([
                'name' => (string) ($options['name'] ?? ''),
                'email' => (string) ($options['email'] ?? ''),
                'password' => (string) ($options['password'] ?? ''),
                'role' => (string) ($options['role'] ?? 'host'),
                'restaurant_id' => (int) $options['restaurant'],
            ]);
            printf("Created user %d (%s) with roles %s\n", $user['id'], $user['email'], implode(',', $user['roles']));
            break;

        case 'set-role':
            $user = $admin->setRole((int) ($options['id'] ?? 0), (string) ($options['role'] ?? 'host'));
            printf("User %d now has roles %s\n", $user['id'], implode(',', $user['roles']));
            break;

        case 'settings':
            $restaurantId = (int) $options['restaurant'];
            if ($options['set'] !== []) {
                $changes = [];
                foreach ($options['set'] as $pair) {
                    [$key, $value] = explode('=', $pair, 2) + [1 => ''];
                    $changes[trim($key)] = trim($value);
                }
                $settings = $admin->updateSettings($restaurantId, $changes);
                echo "Settings updated.\n";
            } else {
                $settings = $admin->settings($restaurantId);
            }
            foreach ($settings as $key => $value) {
                if ($key !== 'restaurant_id') {
                    printf("%-26s %s\n", $key, $value);
                }
            }
            break;

        default:
            fwrite(STDERR, "Unknown command: {$command}\nUse: list | create | set-role | settings\n");
            exit(1);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Error: ' . $exception->getMessage() . "\n");
    exit(1);
}

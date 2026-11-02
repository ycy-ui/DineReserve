<?php

declare(strict_types=1);

/**
 * Create (or recreate) the local SQLite database used by `php -S public`.
 *
 * Usage:
 *   php bin/seed.php                 # database/local.sqlite
 *   php bin/seed.php path/to/db.sqlite
 *   php bin/seed.php --mysql         # uses DB_* environment variables
 */

require dirname(__DIR__) . '/bootstrap/autoload.php';

use DineReserve\Database;

$args = array_slice($argv, 1);

if (in_array('--mysql', $args, true)) {
    $pdo = Database::mysql(
        getenv('DB_HOST') ?: '127.0.0.1',
        getenv('DB_DATABASE') ?: 'dinereserve',
        getenv('DB_USERNAME') ?: 'root',
        getenv('DB_PASSWORD') ?: '',
        (int) (getenv('DB_PORT') ?: 3306)
    );
    $label = 'MySQL';

    foreach (Database::splitStatements(file_get_contents(Database::schemaFileFor('mysql')) ?: '') as $statement) {
        $pdo->exec($statement);
    }
} else {
    $path = $args[0] ?? dirname(__DIR__) . '/database/local.sqlite';
    if ($path !== ':memory:' && is_file($path)) {
        unlink($path);
    }
    $pdo = Database::sqlite($path);
    Database::migrate($pdo);
    $label = 'SQLite (' . $path . ')';
}

if (!Database::isEmpty($pdo)) {
    fwrite(STDERR, "Database already contains a restaurant, skipping seed.\n");
    exit(0);
}

$seed = Database::seedDemo($pdo);

printf(
    "Seeded %s\n  restaurant #%d, %d tables, service periods: %s\n",
    $label,
    $seed['restaurant_id'],
    count($seed['tables']),
    implode(', ', array_keys($seed['periods']))
);

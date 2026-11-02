<?php

declare(strict_types=1);

/**
 * Shared bootstrap: Composer autoloader when available, otherwise a PSR-4
 * fallback so the CLI, the API and the test suite stay runnable with a bare
 * `php` binary (no Composer) — handy on a classroom machine.
 */
$root = dirname(__DIR__);
$composer = $root . '/vendor/autoload.php';

if (is_file($composer)) {
    require_once $composer;

    return;
}

spl_autoload_register(static function (string $class) use ($root): void {
    $prefixes = [
        'DineReserve\\Tests\\' => $root . '/tests/',
        'DineReserve\\' => $root . '/src/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $file = $baseDir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;

            return;
        }
    }
});

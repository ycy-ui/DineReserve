<?php

declare(strict_types=1);

/**
 * Router for PHP's built-in server (local deployment / development).
 *
 *   php -S 0.0.0.0:8080 -t . scripts/router.php
 *
 * Static files (index.html, demo.html, host.html, login.html, assets/, docs/) are
 * served by the built-in server; every /api/* request goes to public/index.php.
 */

$root = dirname(__DIR__);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (str_starts_with($path, '/api')) {
    require $root . '/public/index.php';

    return true;
}

$candidate = realpath($root . $path);
if ($path !== '/' && $candidate !== false && is_file($candidate)) {
    return false;
}

if ($path === '/' || $path === '') {
    header('Content-Type: text/html; charset=utf-8');
    readfile($root . '/index.html');

    return true;
}

http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><meta charset="utf-8"><title>404</title>'
    . '<p>Not found: ' . htmlspecialchars($path) . '</p>';

return true;

# TastyIgniter extension stub — `dinereserve/booking`

This folder is the **integration point** between the DineReserve engine and the TastyIgniter
platform required by the Task 2 URS (§3.1: the system must stay deployable from that code base).

It is deliberately a stub in W1–W6: everything that has business value lives in the
framework-agnostic engine under [`src/`](../../../src), which is what the test suite exercises
(`vendor/bin/phpunit`). Keeping the platform at the edge means the booking rules can be tested
without booting Laravel, MySQL and a full TastyIgniter installation.

## What exists now

| File | Purpose |
|---|---|
| `extension.json` | extension manifest (code `dinereserve.booking`, requires TastyIgniter ^3.7) |
| `Extension.php` | `System\Classes\BaseExtension` subclass with the W7 integration hooks documented |

## W7 work item — wiring it up

1. `composer create-project tastyigniter/tastyigniter` into a deployment tree, copy this folder to
   `extensions/dinereserve/booking`, run `php artisan igniter:up`.
2. Bind the engine in `Extension::register()`:

   ```php
   $this->app->singleton(\DineReserve\AvailabilityService::class, fn ($app) =>
       new \DineReserve\AvailabilityService($app['db']->connection()->getPdo()));
   $this->app->singleton(\DineReserve\BookingService::class, fn ($app) =>
       new \DineReserve\BookingService(
           $app['db']->connection()->getPdo(),
           $app->make(\DineReserve\AvailabilityService::class)
       ));
   ```

3. Register the extension routes (`routes.php`) so that the four endpoints of
   [`docs/03-api.md`](../../../docs/03-api.md) are served by TastyIgniter's router instead of
   the standalone `public/index.php` used in W1–W6.
4. Add the admin controllers (floor plan, waitlist, dashboard) in W10–W11.
5. Point the platform at the MySQL schema: `database/schema-mysql.sql` in the repository root is
   the same DDL the platform migration must produce.

## Why not commit a fork of TastyIgniter?

A vendored copy would add ~100 MB of platform code to the repository and would make GitHub Pages
(the project site and the interactive demo) unbuildable. The URS requirement is that the deployment
*runs on* TastyIgniter — it does not require the platform sources to be part of this repository.

<?php

declare(strict_types=1);

namespace Dinereserve\Booking;

use System\Classes\BaseExtension;

/**
 * TastyIgniter adapter for the DineReserve booking engine.
 *
 * W7 work item: the classes in `src/` are deliberately framework agnostic
 * (PDO only) so that they can be unit-tested without booting TastyIgniter.
 * This extension is the only place where the platform is allowed to leak in:
 *
 *  - `register()` binds the engine services into the TastyIgniter container and
 *    registers the API routes / admin controllers of the extension;
 *  - `boot()` hooks the platform scheduler for the reminder-job (W8).
 *
 * Until W7 this class is a documented stub: it compiles, it is not yet
 * attached to a live TastyIgniter installation (see README.md in this folder).
 */
class Extension extends BaseExtension
{
    public function extensionMeta(): array
    {
        return [
            'name' => 'DineReserve Booking Engine',
            'author' => 'DineReserve Group 46',
            'description' => 'Availability search (REQ-1.1) and booking creation (REQ-1.2).',
            'version' => '0.1.0',
        ];
    }

    /**
     * Bind the engine into the platform container (W7).
     */
    public function register(): void
    {
        // W7: $this->app->singleton(DineReserve\AvailabilityService::class, ...);
        // W7: $this->app->singleton(DineReserve\BookingService::class, ...);
    }

    /**
     * Hook platform events; the reminder scheduler is added in W8.
     */
    public function boot(): void
    {
        // W8: register the 24h / 2h reminder jobs with the TastyIgniter scheduler.
    }

    /**
     * Database migrations of the extension belong to the platform command
     * `php artisan igniter:up`; the SQL lives in the repository root so that the
     * same schema is verifiable in CI without booting the platform.
     *
     * @return array<string,string>
     */
    public function registerDatabaseTables(): array
    {
        return [
            'dinereserve_bookings' => 'bookings',
        ];
    }
}

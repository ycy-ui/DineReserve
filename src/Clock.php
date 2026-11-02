<?php

declare(strict_types=1);

namespace DineReserve;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Injectable clock so that every time-dependent rule (date range, cut-off,
 * cache expiry, reminder schedule) is testable without sleeping.
 */
interface Clock
{
    public function now(): DateTimeImmutable;

    public function timezone(): DateTimeZone;
}

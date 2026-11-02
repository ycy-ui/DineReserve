<?php

declare(strict_types=1);

namespace DineReserve;

use DateTimeImmutable;
use DateTimeZone;

final class FrozenClock implements Clock
{
    private DateTimeImmutable $now;

    private DateTimeZone $zone;

    public function __construct(string $isoDateTime, string $timezone = 'Asia/Macau')
    {
        $this->zone = new DateTimeZone($timezone);
        $this->now = new DateTimeImmutable($isoDateTime, $this->zone);
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function timezone(): DateTimeZone
    {
        return $this->zone;
    }

    /** Move the frozen clock forward (used by tests that exercise cut-off). */
    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}

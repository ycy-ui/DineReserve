<?php

declare(strict_types=1);

namespace DineReserve;

use DateTimeImmutable;
use DateTimeZone;

final class SystemClock implements Clock
{
    private DateTimeZone $zone;

    public function __construct(string $timezone = 'Asia/Macau')
    {
        $this->zone = new DateTimeZone($timezone);
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->zone);
    }

    public function timezone(): DateTimeZone
    {
        return $this->zone;
    }
}

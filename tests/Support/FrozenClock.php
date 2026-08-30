<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Clock\Clock;
use DateTimeImmutable;

final class FrozenClock implements Clock
{
    public function __construct(private DateTimeImmutable $now) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function set(DateTimeImmutable $now): void
    {
        $this->now = $now;
    }
}

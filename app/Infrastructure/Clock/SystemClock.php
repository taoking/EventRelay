<?php

declare(strict_types=1);

namespace App\Infrastructure\Clock;

use App\Application\Clock\Clock;
use DateTimeImmutable;

final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable;
    }
}

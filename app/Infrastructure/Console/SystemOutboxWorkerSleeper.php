<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

final class SystemOutboxWorkerSleeper implements OutboxWorkerSleeper
{
    public function sleep(int $seconds): void
    {
        sleep($seconds);
    }
}

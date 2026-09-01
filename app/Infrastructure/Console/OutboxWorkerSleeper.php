<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

interface OutboxWorkerSleeper
{
    public function sleep(int $seconds): void;
}

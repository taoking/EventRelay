<?php

declare(strict_types=1);

namespace App\Application\Operations;

use RuntimeException;
use Throwable;

final class OperationalDataUnavailable extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct('Operational durable-state data is unavailable.', 0, $previous);
    }
}

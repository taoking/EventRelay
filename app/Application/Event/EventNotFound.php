<?php

declare(strict_types=1);

namespace App\Application\Event;

use RuntimeException;

final class EventNotFound extends RuntimeException
{
    public function __construct(string $id)
    {
        parent::__construct(sprintf('Event "%s" was not found.', $id));
    }
}

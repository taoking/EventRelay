<?php

declare(strict_types=1);

namespace App\Application\Event;

final readonly class CreatedEventResult
{
    public function __construct(
        public EventData $event,
        public bool $created,
    ) {}
}

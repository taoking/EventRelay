<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Domain\Event\Event;
use stdClass;

final readonly class CreateEvent
{
    public function __construct(
        private EventRepository $events,
    ) {}

    public function handle(string $type, stdClass $payload): EventData
    {
        return EventData::fromDomain($this->events->save(Event::create($type, $payload)));
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Event;

final readonly class FindEvent
{
    public function __construct(
        private EventRepository $events,
    ) {}

    public function handle(string $id): EventData
    {
        $event = $this->events->find($id);

        if ($event === null) {
            throw new EventNotFound($id);
        }

        return EventData::fromDomain($event);
    }
}

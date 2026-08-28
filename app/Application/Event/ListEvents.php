<?php

declare(strict_types=1);

namespace App\Application\Event;

final readonly class ListEvents
{
    public function __construct(
        private EventRepository $events,
    ) {}

    /**
     * @return list<EventData>
     */
    public function handle(): array
    {
        return array_map(EventData::fromDomain(...), $this->events->all());
    }
}

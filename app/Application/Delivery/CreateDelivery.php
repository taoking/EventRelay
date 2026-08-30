<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Application\Event\EventNotFound;
use App\Application\Event\EventRepository;
use App\Domain\Endpoint\EndpointId;

final readonly class CreateDelivery
{
    public function __construct(
        private EventRepository $events,
        private DeliverySnapshotCreator $snapshots,
    ) {}

    public function handle(string $eventId, string $endpointId): DeliveryData
    {
        $event = $this->events->find($eventId);

        if ($event === null) {
            throw new EventNotFound($eventId);
        }

        $delivery = $this->snapshots->createOrGetSnapshot($event->id(), EndpointId::fromString($endpointId));

        return DeliveryData::fromDomain($delivery);
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Application\Endpoint\EndpointNotFound;
use App\Application\Endpoint\EndpointRepository;
use App\Application\Event\EventNotFound;
use App\Application\Event\EventRepository;
use App\Domain\Delivery\Delivery;

final readonly class CreateDelivery
{
    public function __construct(
        private EventRepository $events,
        private EndpointRepository $endpoints,
        private DeliveryRepository $deliveries,
    ) {}

    public function handle(string $eventId, string $endpointId): DeliveryData
    {
        $event = $this->events->find($eventId);

        if ($event === null) {
            throw new EventNotFound($eventId);
        }

        $endpoint = $this->endpoints->find($endpointId);

        if ($endpoint === null) {
            throw new EndpointNotFound($endpointId);
        }

        return DeliveryData::fromDomain(
            $this->deliveries->createOrGet(Delivery::create($event->id(), $endpoint->id(), $endpoint->url())),
        );
    }
}

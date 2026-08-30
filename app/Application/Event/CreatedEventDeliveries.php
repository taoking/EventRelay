<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Domain\Delivery\DeliveryId;

final readonly class CreatedEventDeliveries
{
    /**
     * @param  list<DeliveryId>  $deliveryIds
     */
    public function __construct(
        public EventData $event,
        public array $deliveryIds,
    ) {}
}

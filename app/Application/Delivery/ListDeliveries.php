<?php

declare(strict_types=1);

namespace App\Application\Delivery;

final readonly class ListDeliveries
{
    public function __construct(
        private DeliveryRepository $deliveries,
    ) {}

    /**
     * @return list<DeliveryData>
     */
    public function handle(): array
    {
        return array_map(DeliveryData::fromDomain(...), $this->deliveries->all());
    }
}

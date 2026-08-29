<?php

declare(strict_types=1);

namespace App\Application\Delivery;

final readonly class FindDelivery
{
    public function __construct(
        private DeliveryRepository $deliveries,
    ) {}

    public function handle(string $id): DeliveryData
    {
        $delivery = $this->deliveries->find($id);

        if ($delivery === null) {
            throw new DeliveryNotFound($id);
        }

        return DeliveryData::fromDomain($delivery);
    }
}

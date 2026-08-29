<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Domain\Delivery\DeliveryId;
use App\Domain\Delivery\DeliveryStatus;

final readonly class ProcessPendingDelivery
{
    public function __construct(
        private DeliveryRepository $deliveries,
    ) {}

    public function handle(DeliveryId $deliveryId): void
    {
        $delivery = $this->deliveries->find($deliveryId->toString());

        if ($delivery === null) {
            throw new DeliveryNotFound($deliveryId->toString());
        }

        match ($delivery->status()) {
            DeliveryStatus::Pending => null,
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Domain\Delivery\DeliveryId;

final readonly class ListDeliveryAttempts
{
    public function __construct(
        private DeliveryRepository $deliveries,
        private DeliveryExecutionRepository $execution,
    ) {}

    /**
     * @return list<DeliveryAttemptData>
     */
    public function handle(string $deliveryId): array
    {
        if ($this->deliveries->find($deliveryId) === null) {
            throw new DeliveryNotFound($deliveryId);
        }

        return array_map(DeliveryAttemptData::fromDomain(...), $this->execution->attempts(DeliveryId::fromString($deliveryId)));
    }
}

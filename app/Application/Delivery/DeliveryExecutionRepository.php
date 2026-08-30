<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Domain\Delivery\Delivery;
use App\Domain\Delivery\DeliveryId;
use App\Domain\DeliveryAttempt\DeliveryAttempt;

interface DeliveryExecutionRepository
{
    public function claim(DeliveryId $deliveryId): ?ClaimedDelivery;

    public function finalize(Delivery $delivery, DeliveryAttempt $attempt): void;

    /**
     * @return list<DeliveryAttempt>
     */
    public function attempts(DeliveryId $deliveryId): array;
}

<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Domain\Delivery\DeliveryId;
use DateTimeImmutable;

interface DeliveryQueue
{
    public function enqueue(DeliveryId $deliveryId): void;

    public function schedule(DeliveryId $deliveryId, DateTimeImmutable $availableAt): void;
}

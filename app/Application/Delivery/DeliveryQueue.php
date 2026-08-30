<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Domain\Delivery\DeliveryId;

interface DeliveryQueue
{
    public function enqueue(DeliveryId $deliveryId): void;
}

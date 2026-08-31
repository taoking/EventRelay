<?php

declare(strict_types=1);

namespace App\Infrastructure\RabbitMq;

use App\Domain\Delivery\DeliveryId;

interface RabbitMqDeliveryPublisher
{
    public function publish(DeliveryId $deliveryId): void;
}

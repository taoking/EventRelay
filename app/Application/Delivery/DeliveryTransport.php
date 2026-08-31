<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Domain\Delivery\DeliveryId;

/**
 * 只负责立即传递已经持久化的 Delivery UUID。
 *
 * 业务延迟由 MySQL Outbox 的 available_at 决定，不能由具体 Broker 重算。
 */
interface DeliveryTransport
{
    public function publish(DeliveryId $deliveryId): void;
}

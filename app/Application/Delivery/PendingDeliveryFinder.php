<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Domain\Delivery\DeliveryId;

interface PendingDeliveryFinder
{
    /**
     * @return list<DeliveryId>
     */
    public function findPending(int $limit): array;
}

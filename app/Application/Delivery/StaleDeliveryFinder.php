<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Domain\Delivery\DeliveryId;
use DateTimeImmutable;

interface StaleDeliveryFinder
{
    /**
     * @return list<DeliveryId>
     */
    public function findStale(DateTimeImmutable $cutoff, int $limit): array;
}

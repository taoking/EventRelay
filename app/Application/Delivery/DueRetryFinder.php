<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Domain\Delivery\DeliveryId;
use DateTimeImmutable;

interface DueRetryFinder
{
    /**
     * @return list<DeliveryId>
     */
    public function findDue(DateTimeImmutable $now, int $limit): array;
}

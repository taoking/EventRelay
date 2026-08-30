<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use DateTimeImmutable;

interface DeliveryOutboxIntentFinder
{
    /**
     * @return list<DeliveryExecutionIntent>
     */
    public function findPendingInitial(int $limit): array;

    /**
     * @return list<DeliveryExecutionIntent>
     */
    public function findDueRetries(DateTimeImmutable $now, int $limit): array;
}

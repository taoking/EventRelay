<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Domain\Delivery\Delivery;
use App\Domain\Delivery\DeliveryId;
use App\Domain\DeliveryAttempt\DeliveryAttempt;
use DateTimeImmutable;

interface DeliveryExecutionRepository
{
    public function claim(DeliveryId $deliveryId, DateTimeImmutable $now): ?ClaimedDelivery;

    public function finalize(Delivery $delivery, DeliveryAttempt $attempt): void;

    public function finalizeAndScheduleRetry(
        Delivery $delivery,
        DeliveryAttempt $attempt,
        DeliveryExecutionIntent $intent,
    ): void;

    public function recoverStale(
        DeliveryId $deliveryId,
        DateTimeImmutable $cutoff,
        DateTimeImmutable $now,
        DeliveryRetryPolicy $policy,
    ): ?StaleRecoveryResult;

    /**
     * @return list<DeliveryAttempt>
     */
    public function attempts(DeliveryId $deliveryId): array;
}

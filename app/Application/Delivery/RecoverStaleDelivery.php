<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Application\Clock\Clock;
use App\Domain\Delivery\DeliveryId;
use DateInterval;

final readonly class RecoverStaleDelivery
{
    public const int StaleThresholdSeconds = 60;

    public function __construct(
        private DeliveryExecutionRepository $execution,
        private DeliveryRetryPolicy $retryPolicy,
        private Clock $clock,
    ) {}

    public function handle(DeliveryId $deliveryId): ?StaleRecoveryResult
    {
        $now = $this->clock->now();
        $result = $this->execution->recoverStale(
            $deliveryId,
            $now->sub(new DateInterval('PT'.self::StaleThresholdSeconds.'S')),
            $now,
            $this->retryPolicy,
        );

        return $result;
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Application\Clock\Clock;
use DateInterval;
use InvalidArgumentException;

final readonly class RecoverStaleDeliveries
{
    public const int DefaultLimit = 100;

    public const int MaximumLimit = 1000;

    public function __construct(
        private StaleDeliveryFinder $staleDeliveries,
        private RecoverStaleDelivery $recovery,
        private Clock $clock,
    ) {}

    public function handle(int $limit = self::DefaultLimit): EnqueuePendingDeliveriesResult
    {
        if ($limit < 1 || $limit > self::MaximumLimit) {
            throw new InvalidArgumentException(sprintf('The stale recovery limit must be between 1 and %d.', self::MaximumLimit));
        }

        $now = $this->clock->now();
        $cutoff = $now->sub(new DateInterval('PT'.RecoverStaleDelivery::StaleThresholdSeconds.'S'));
        $recovered = 0;
        $skipped = 0;

        foreach ($this->staleDeliveries->findStale($cutoff, $limit) as $deliveryId) {
            if ($this->recovery->handle($deliveryId) === null) {
                $skipped++;
            } else {
                $recovered++;
            }
        }

        return new EnqueuePendingDeliveriesResult($recovered, $skipped);
    }
}

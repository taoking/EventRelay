<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use InvalidArgumentException;

final readonly class EnqueuePendingDeliveries
{
    public const int DefaultLimit = 100;

    public const int MaximumLimit = 1000;

    public function __construct(
        private PendingDeliveryFinder $pendingDeliveries,
        private DeliveryQueue $queue,
    ) {}

    public function handle(int $limit = self::DefaultLimit): EnqueuePendingDeliveriesResult
    {
        if ($limit < 1 || $limit > self::MaximumLimit) {
            throw new InvalidArgumentException(sprintf(
                'The pending delivery limit must be between 1 and %d.',
                self::MaximumLimit,
            ));
        }

        $enqueued = 0;
        $failed = 0;

        foreach ($this->pendingDeliveries->findPending($limit) as $deliveryId) {
            try {
                $this->queue->enqueue($deliveryId);
                $enqueued++;
            } catch (DeliveryQueueUnavailable) {
                $failed++;
            }
        }

        return new EnqueuePendingDeliveriesResult($enqueued, $failed);
    }
}

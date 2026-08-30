<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Application\Clock\Clock;
use InvalidArgumentException;

final readonly class EnqueueDueRetries
{
    public const int DefaultLimit = 100;

    public const int MaximumLimit = 1000;

    public function __construct(
        private DueRetryFinder $dueRetries,
        private DeliveryQueue $queue,
        private Clock $clock,
    ) {}

    public function handle(int $limit = self::DefaultLimit): EnqueuePendingDeliveriesResult
    {
        if ($limit < 1 || $limit > self::MaximumLimit) {
            throw new InvalidArgumentException(sprintf('The due retry limit must be between 1 and %d.', self::MaximumLimit));
        }

        $enqueued = 0;
        $failed = 0;

        foreach ($this->dueRetries->findDue($this->clock->now(), $limit) as $deliveryId) {
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

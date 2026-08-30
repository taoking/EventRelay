<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Application\Clock\Clock;
use InvalidArgumentException;

final readonly class EnqueuePendingDeliveries
{
    public const int DefaultLimit = 100;

    public const int MaximumLimit = 1000;

    public function __construct(
        private DeliveryOutboxIntentFinder $intents,
        private DeliveryOutboxWriter $outbox,
        private Clock $clock,
    ) {}

    public function handle(int $limit = self::DefaultLimit): EnsureDeliveryOutboxIntentsResult
    {
        if ($limit < 1 || $limit > self::MaximumLimit) {
            throw new InvalidArgumentException(sprintf(
                'The pending delivery limit must be between 1 and %d.',
                self::MaximumLimit,
            ));
        }

        $ensured = 0;
        foreach ($this->intents->findPendingInitial($limit) as $intent) {
            $this->outbox->schedule($intent, $this->clock->now());
            $ensured++;
        }

        return new EnsureDeliveryOutboxIntentsResult($ensured);
    }
}

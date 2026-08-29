<?php

declare(strict_types=1);

namespace App\Application\Delivery;

final readonly class EnqueuePendingDeliveriesResult
{
    public function __construct(
        public int $enqueued,
        public int $failed,
    ) {}
}

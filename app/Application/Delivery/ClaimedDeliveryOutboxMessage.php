<?php

declare(strict_types=1);

namespace App\Application\Delivery;

final readonly class ClaimedDeliveryOutboxMessage
{
    public function __construct(
        public string $publicId,
        public DeliveryExecutionIntent $intent,
        public string $claimToken,
    ) {}
}

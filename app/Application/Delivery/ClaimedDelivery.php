<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Domain\Delivery\Delivery;
use App\Domain\DeliveryAttempt\DeliveryAttempt;

final readonly class ClaimedDelivery
{
    public function __construct(
        public Delivery $delivery,
        public DeliveryAttempt $attempt,
    ) {}
}

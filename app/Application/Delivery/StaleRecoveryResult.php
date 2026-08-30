<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Domain\Delivery\Delivery;
use App\Domain\DeliveryAttempt\DeliveryAttempt;
use DateTimeImmutable;

final readonly class StaleRecoveryResult
{
    public function __construct(
        public Delivery $delivery,
        public DeliveryAttempt $attempt,
        public ?DateTimeImmutable $availableAt,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Domain\Delivery\DeliveryId;
use RuntimeException;
use Throwable;

final class DeliveryQueueUnavailable extends RuntimeException
{
    public function __construct(
        public readonly DeliveryId $deliveryId,
        Throwable $previous,
    ) {
        parent::__construct('Delivery queue publication is unavailable.', 0, $previous);
    }
}

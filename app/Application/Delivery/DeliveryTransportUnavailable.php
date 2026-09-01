<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Domain\Delivery\DeliveryId;
use RuntimeException;
use Throwable;

final class DeliveryTransportUnavailable extends RuntimeException
{
    public function __construct(
        public readonly DeliveryId $deliveryId,
        public readonly string $transport,
        Throwable $previous,
    ) {
        parent::__construct('Delivery transport publication is unavailable.', 0, $previous);
    }
}

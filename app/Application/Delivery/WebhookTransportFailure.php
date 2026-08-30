<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Domain\DeliveryAttempt\DeliveryFailureType;
use RuntimeException;

final class WebhookTransportFailure extends RuntimeException
{
    public function __construct(
        public readonly DeliveryFailureType $type,
        string $message,
        public readonly int $durationMs,
    ) {
        parent::__construct($message);
    }
}

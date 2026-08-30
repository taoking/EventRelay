<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Domain\Delivery\DeliveryId;

interface WebhookSigner
{
    public function sign(string $secret, int $timestamp, DeliveryId $deliveryId, int $attemptNumber, string $body): string;
}

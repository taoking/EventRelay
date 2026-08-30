<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Domain\Delivery\DeliveryId;

final class HmacWebhookSigner implements WebhookSigner
{
    public function sign(string $secret, int $timestamp, DeliveryId $deliveryId, int $attemptNumber, string $body): string
    {
        $canonical = "v1\n{$timestamp}\n{$deliveryId->toString()}\n{$attemptNumber}\n{$body}";

        return hash_hmac('sha256', $canonical, $secret);
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Delivery;

final readonly class WebhookResponse
{
    public function __construct(
        public int $statusCode,
        public int $durationMs,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Application\Delivery;

final readonly class WebhookRequest
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $body,
        public array $headers,
    ) {}
}

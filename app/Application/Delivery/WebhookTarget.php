<?php

declare(strict_types=1);

namespace App\Application\Delivery;

final readonly class WebhookTarget
{
    public function __construct(
        public string $url,
        public string $host,
        public int $port,
        public string $ip,
        public bool $isIpLiteral = false,
    ) {}
}

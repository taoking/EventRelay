<?php

declare(strict_types=1);

namespace App\Application\EndpointSigningSecret;

final readonly class RotatedSigningSecretData
{
    public function __construct(
        public string $keyId,
        public int $version,
        public string $secret,
        public string $createdAt,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Application\EndpointSigningSecret;

use App\Application\Clock\Clock;
use App\Domain\EndpointSigningSecret\EndpointSigningSecretId;

final readonly class RotateEndpointSigningSecret
{
    public function __construct(
        private EndpointSigningSecretRepository $secrets,
        private SecretGenerator $generator,
        private SecretCipher $cipher,
        private Clock $clock,
    ) {}

    public function handle(string $endpointId): RotatedSigningSecretData
    {
        $plaintext = $this->generator->generate();
        $secret = $this->secrets->rotate(
            $endpointId,
            EndpointSigningSecretId::generate(),
            $this->cipher->encrypt($plaintext),
            $this->clock->now(),
        );

        return new RotatedSigningSecretData(
            $secret->id()->toString(),
            $secret->version(),
            $plaintext,
            $secret->createdAt()->format(DATE_ATOM),
        );
    }
}

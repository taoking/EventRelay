<?php

declare(strict_types=1);

namespace App\Infrastructure\EndpointSigningSecret;

use App\Application\EndpointSigningSecret\SecretGenerator;

final class RandomSecretGenerator implements SecretGenerator
{
    public function generate(): string
    {
        return 'whsec_'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}

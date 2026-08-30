<?php

declare(strict_types=1);

namespace App\Application\EndpointSigningSecret;

interface SecretGenerator
{
    public function generate(): string;
}

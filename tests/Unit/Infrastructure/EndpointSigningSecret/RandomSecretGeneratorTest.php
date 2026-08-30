<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\EndpointSigningSecret;

use App\Infrastructure\EndpointSigningSecret\RandomSecretGenerator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RandomSecretGeneratorTest extends TestCase
{
    #[Test]
    public function it_generates_a_256_bit_base64url_secret_with_the_public_prefix(): void
    {
        $secret = (new RandomSecretGenerator)->generate();

        self::assertMatchesRegularExpression('/^whsec_[A-Za-z0-9_-]{43}$/', $secret);
    }
}

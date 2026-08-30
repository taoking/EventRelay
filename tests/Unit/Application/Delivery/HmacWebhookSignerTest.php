<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Delivery;

use App\Application\Delivery\HmacWebhookSigner;
use App\Domain\Delivery\DeliveryId;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class HmacWebhookSignerTest extends TestCase
{
    #[Test]
    public function it_uses_the_fixed_v1_canonical_bytes_and_full_secret_value(): void
    {
        $signature = (new HmacWebhookSigner)->sign(
            'whsec_test_vector_secret',
            1700000000,
            DeliveryId::fromString('123e4567-e89b-42d3-a456-426614174000'),
            2,
            '{"payload":{}}',
        );

        // 由 OpenSSL 独立预先计算；不能以被测 hash_hmac 调用作为预期值。
        self::assertSame('1d81bf08b2ea8fb41eae8841353b893ded36c4dd7152b78808c1fd733db9b3b8', $signature);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $signature);
    }
}

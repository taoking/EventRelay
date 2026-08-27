<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Endpoint\Endpoint;
use App\Domain\Endpoint\EndpointStatus;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EndpointTest extends TestCase
{
    #[Test]
    public function it_generates_a_public_uuid_v4_and_defaults_to_the_supplied_status(): void
    {
        $endpoint = Endpoint::create(' Billing events ', ' https://example.test/webhooks/billing ', EndpointStatus::Active);

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $endpoint->id()->toString(),
        );
        self::assertSame('Billing events', $endpoint->name());
        self::assertSame('https://example.test/webhooks/billing', $endpoint->url());
        self::assertSame(EndpointStatus::Active, $endpoint->status());
    }

    #[Test]
    public function it_rejects_blank_names(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Endpoint::create('', 'https://example.test/webhooks', EndpointStatus::Active);
    }

    #[Test]
    public function it_rejects_overlong_names(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Endpoint::create(str_repeat('a', 121), 'https://example.test/webhooks', EndpointStatus::Active);
    }

    #[Test]
    public function it_rejects_invalid_or_non_http_urls(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Endpoint::create('Billing events', 'ftp://example.test/webhooks', EndpointStatus::Active);
    }

    #[Test]
    public function it_preserves_its_public_identifier_when_updated(): void
    {
        $endpoint = Endpoint::create('Billing events', 'https://example.test/webhooks', EndpointStatus::Active);

        $updated = $endpoint->update('Invoices', 'http://example.test/invoices', EndpointStatus::Disabled);

        self::assertSame($endpoint->id()->toString(), $updated->id()->toString());
        self::assertSame('Invoices', $updated->name());
        self::assertSame('http://example.test/invoices', $updated->url());
        self::assertSame(EndpointStatus::Disabled, $updated->status());
        self::assertGreaterThanOrEqual($endpoint->updatedAt(), $updated->updatedAt());
    }
}

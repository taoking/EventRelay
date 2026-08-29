<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Delivery\Delivery;
use App\Domain\Delivery\DeliveryId;
use App\Domain\Delivery\DeliveryStatus;
use App\Domain\Endpoint\EndpointId;
use App\Domain\Event\EventId;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DeliveryTest extends TestCase
{
    #[Test]
    public function it_creates_a_pending_delivery_with_a_public_uuid_v4(): void
    {
        $delivery = Delivery::create(EventId::generate(), EndpointId::generate());

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $delivery->id()->toString(),
        );
        self::assertSame(DeliveryStatus::Pending, $delivery->status());
        self::assertEquals($delivery->createdAt(), $delivery->updatedAt());
    }

    #[Test]
    public function it_preserves_its_state_when_reconstituted(): void
    {
        $id = DeliveryId::generate();
        $eventId = EventId::generate();
        $endpointId = EndpointId::generate();
        $createdAt = new DateTimeImmutable('2026-08-29T08:00:00+00:00');
        $updatedAt = new DateTimeImmutable('2026-08-29T08:01:00+00:00');

        $delivery = Delivery::reconstitute(
            $id,
            $eventId,
            $endpointId,
            DeliveryStatus::Pending,
            $createdAt,
            $updatedAt,
        );

        self::assertSame($id->toString(), $delivery->id()->toString());
        self::assertSame($eventId->toString(), $delivery->eventId()->toString());
        self::assertSame($endpointId->toString(), $delivery->endpointId()->toString());
        self::assertSame(DeliveryStatus::Pending, $delivery->status());
        self::assertEquals($createdAt, $delivery->createdAt());
        self::assertEquals($updatedAt, $delivery->updatedAt());
    }
}

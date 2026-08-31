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
        $delivery = Delivery::create(EventId::generate(), EndpointId::generate(), 'https://receiver.example/webhook');

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $delivery->id()->toString(),
        );
        self::assertSame(DeliveryStatus::Pending, $delivery->status());
        self::assertNull($delivery->nextAttemptAt());
        self::assertSame('https://receiver.example/webhook', $delivery->targetUrl());
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
            'https://receiver.example/webhook',
            null,
            null,
            DeliveryStatus::Pending,
            $createdAt,
            $updatedAt,
        );

        self::assertSame($id->toString(), $delivery->id()->toString());
        self::assertSame($eventId->toString(), $delivery->eventId()->toString());
        self::assertSame($endpointId->toString(), $delivery->endpointId()->toString());
        self::assertSame(DeliveryStatus::Pending, $delivery->status());
        self::assertSame('https://receiver.example/webhook', $delivery->targetUrl());
        self::assertEquals($createdAt, $delivery->createdAt());
        self::assertEquals($updatedAt, $delivery->updatedAt());
    }

    #[Test]
    public function it_allows_only_the_delivery_state_machine_transitions(): void
    {
        $delivery = Delivery::create(EventId::generate(), EndpointId::generate(), 'https://receiver.example/webhook');

        $processing = $delivery->claim();
        self::assertSame(DeliveryStatus::Processing, $processing->status());
        self::assertSame(DeliveryStatus::Succeeded, $processing->succeed()->status());
        self::assertSame(DeliveryStatus::Failed, $processing->fail()->status());

        $this->expectException(\LogicException::class);
        $delivery->succeed();
    }

    #[Test]
    public function it_rejects_terminal_and_skipped_state_transitions(): void
    {
        $pending = Delivery::create(EventId::generate(), EndpointId::generate(), 'https://receiver.example/webhook');
        $processing = $pending->claim();
        $succeeded = $processing->succeed();
        $failed = $processing->fail();

        $invalidTransitions = [
            static fn (): Delivery => $pending->fail(),
            static fn (): Delivery => $succeeded->claim(),
            static fn (): Delivery => $failed->claim(),
        ];

        foreach ($invalidTransitions as $transition) {
            try {
                $transition();
                self::fail('Terminal or skipped state transition must be rejected.');
            } catch (\LogicException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function it_requires_next_attempt_at_only_for_a_retry_scheduled_delivery(): void
    {
        $now = new DateTimeImmutable('2026-08-31T12:00:00+00:00');
        $delivery = Delivery::create(EventId::generate(), EndpointId::generate(), 'https://receiver.example/webhook');
        $retry = $delivery->claim($now)->scheduleRetry($now->modify('+10 seconds'), $now);

        self::assertSame(DeliveryStatus::RetryScheduled, $retry->status());
        self::assertEquals($now->modify('+10 seconds'), $retry->nextAttemptAt());
        self::assertSame(DeliveryStatus::Processing, $retry->claim($now->modify('+10 seconds'))->status());

        $this->expectException(\LogicException::class);
        $retry->claim($now);
    }
}

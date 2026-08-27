<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Event\Event;
use App\Domain\Event\EventId;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EventTest extends TestCase
{
    #[Test]
    public function it_creates_an_immutable_event_with_a_public_uuid(): void
    {
        $event = Event::create('order.paid', (object) [
            'order' => (object) ['id' => 'order_123'],
        ]);

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $event->id()->toString(),
        );
        self::assertSame('order.paid', $event->type());
        self::assertSame(['order' => ['id' => 'order_123']], $event->payload());
    }

    #[Test]
    public function it_accepts_the_supported_event_type_formats(): void
    {
        foreach (['order.paid', 'user_created', 'invoice-paid.v2'] as $type) {
            $event = Event::create($type, (object) []);

            self::assertSame($type, $event->type());
        }
    }

    #[Test]
    public function it_rejects_invalid_event_types(): void
    {
        foreach (['', ' ', 'Order.Paid', 'order paid', '.order', 'order.', str_repeat('a', 121)] as $type) {
            try {
                Event::create($type, (object) []);
                self::fail(sprintf('Expected "%s" to be rejected.', $type));
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function it_rejects_non_object_payloads(): void
    {
        foreach (['payload', 42, true, []] as $payload) {
            try {
                Event::create('order.paid', $payload);
                self::fail('Expected the non-object payload to be rejected.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function it_preserves_its_identifier_when_reconstituted(): void
    {
        $event = Event::create('order.paid', (object) []);

        $reconstituted = Event::reconstitute(
            EventId::fromString($event->id()->toString()),
            $event->type(),
            (object) $event->payload(),
            $event->createdAt(),
        );

        self::assertSame($event->id()->toString(), $reconstituted->id()->toString());
        self::assertSame([], $reconstituted->payload());
    }
}

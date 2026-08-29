<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Endpoint\EndpointId;
use App\Domain\Event\Event;
use App\Domain\Event\EventType;
use App\Domain\Subscription\EndpointSubscriptions;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EndpointSubscriptionsTest extends TestCase
{
    #[Test]
    public function it_deduplicates_and_sorts_event_types_for_an_endpoint(): void
    {
        $subscriptions = EndpointSubscriptions::replace(
            EndpointId::generate(),
            ['user.created', 'order.paid', 'user.created'],
        );

        self::assertSame(
            ['order.paid', 'user.created'],
            array_map(static fn (EventType $type): string => $type->toString(), $subscriptions->types()),
        );
    }

    #[Test]
    public function it_reuses_the_event_type_invariant(): void
    {
        foreach (['', 'Order.Paid', 'order paid', '.order', 'order.', str_repeat('a', 121)] as $type) {
            try {
                Event::create($type, (object) []);
                self::fail(sprintf('Expected Event type "%s" to be rejected.', $type));
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }

            try {
                EndpointSubscriptions::replace(EndpointId::generate(), [$type]);
                self::fail(sprintf('Expected subscription type "%s" to be rejected.', $type));
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Application\Delivery\DeliverySnapshotCreator;
use App\Application\Delivery\DeliveryTransport;
use App\Application\Delivery\DeliveryTransportUnavailable;
use App\Application\Event\CreateEvent;
use App\Application\Subscription\SubscriptionMatcher;
use App\Domain\Delivery\Delivery;
use App\Domain\Delivery\DeliveryId;
use App\Domain\Endpoint\EndpointId;
use App\Domain\Event\EventId;
use App\Domain\Event\EventType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

final class DeliveryQueueSchedulingTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_event_without_matching_deliveries_does_not_create_an_outbox_intent(): void
    {
        $this->postEvent('order.paid')->assertCreated();

        $this->assertDatabaseCount('events', 1);
        $this->assertDatabaseCount('deliveries', 0);
        $this->assertDatabaseCount('delivery_outbox_messages', 0);
    }

    public function test_each_committed_matching_delivery_creates_an_initial_outbox_intent_without_direct_queue_publication(): void
    {
        $first = $this->createEndpoint('First queued endpoint');
        $second = $this->createEndpoint('Second queued endpoint');
        $this->replaceSubscriptions($first, ['order.paid']);
        $this->replaceSubscriptions($second, ['order.paid']);

        $queueWasCalled = false;
        $this->app->instance(DeliveryTransport::class, new class($queueWasCalled) implements DeliveryTransport
        {
            public function __construct(bool &$queueWasCalled)
            {
                $this->queueWasCalled = &$queueWasCalled;
            }

            private bool $queueWasCalled;

            public function publish(DeliveryId $deliveryId): void
            {
                $this->queueWasCalled = true;
                throw new RuntimeException('CreateEvent must not publish Redis directly.');
            }
        });

        $response = $this->postEvent('order.paid')->assertCreated();
        $eventId = (string) $response->json('data.id');

        self::assertFalse($queueWasCalled);
        self::assertSame($this->deliveryIdsForEvent($eventId), DB::table('delivery_outbox_messages')
            ->join('deliveries', 'delivery_outbox_messages.delivery_id', '=', 'deliveries.id')
            ->orderBy('delivery_outbox_messages.id')
            ->pluck('deliveries.public_id')
            ->all());
        $this->assertDatabaseCount('delivery_outbox_messages', 2);
        $this->assertDatabaseHas('delivery_outbox_messages', [
            'message_type' => 'delivery.process',
            'attempt_number' => 1,
            'status' => 'pending',
        ]);
    }

    public function test_rollback_removes_event_delivery_and_outbox_intent_together(): void
    {
        $first = $this->createEndpoint('First rollback endpoint');
        $second = $this->createEndpoint('Second rollback endpoint');
        $this->app->instance(
            SubscriptionMatcher::class,
            new class($first, $second) implements SubscriptionMatcher
            {
                public function __construct(
                    private string $first,
                    private string $second,
                ) {}

                public function matchingActiveEndpointIds(EventType $eventType): array
                {
                    return [EndpointId::fromString($this->first), EndpointId::fromString($this->second)];
                }
            },
        );

        $snapshots = app(DeliverySnapshotCreator::class);
        $this->app->instance(
            DeliverySnapshotCreator::class,
            new class($snapshots) implements DeliverySnapshotCreator
            {
                private int $calls = 0;

                public function __construct(
                    private DeliverySnapshotCreator $snapshots,
                ) {}

                public function createOrGetSnapshot(EventId $eventId, EndpointId $endpointId): Delivery
                {
                    $this->calls++;

                    if ($this->calls === 2) {
                        throw new RuntimeException('Forced delivery persistence failure.');
                    }

                    return $this->snapshots->createOrGetSnapshot($eventId, $endpointId);
                }
            },
        );

        try {
            app(CreateEvent::class)->handle('order.paid', (object) ['source' => 'queue-rollback-test']);
            self::fail('The second delivery persistence attempt must fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('Forced delivery persistence failure.', $exception->getMessage());
        }

        $this->assertDatabaseCount('events', 0);
        $this->assertDatabaseCount('deliveries', 0);
        $this->assertDatabaseCount('delivery_outbox_messages', 0);
    }

    public function test_redis_unavailability_cannot_affect_event_commit_because_create_event_does_not_publish_directly(): void
    {
        $endpointId = $this->createEndpoint('Unavailable queue endpoint');
        $this->replaceSubscriptions($endpointId, ['order.paid']);

        $this->app->instance(
            DeliveryTransport::class,
            new class implements DeliveryTransport
            {
                public function publish(DeliveryId $deliveryId): void
                {
                    throw new DeliveryTransportUnavailable($deliveryId, 'redis', new RuntimeException('Redis is unavailable.'));
                }
            },
        );

        $this->postEvent('order.paid')->assertCreated();

        $this->assertDatabaseCount('events', 1);
        $this->assertDatabaseCount('deliveries', 1);
        $this->assertDatabaseCount('delivery_outbox_messages', 1);
        $this->assertDatabaseHas('deliveries', ['status' => 'pending']);
        $this->assertDatabaseHas('delivery_outbox_messages', ['status' => 'pending']);
    }

    public function test_create_event_does_not_call_queue_even_when_the_queue_has_an_unknown_error(): void
    {
        $endpointId = $this->createEndpoint('Unknown queue failure endpoint');
        $this->replaceSubscriptions($endpointId, ['order.paid']);

        $this->app->instance(
            DeliveryTransport::class,
            new class implements DeliveryTransport
            {
                public function publish(DeliveryId $deliveryId): void
                {
                    throw new RuntimeException('Unexpected queue programming failure.');
                }
            },
        );

        app(CreateEvent::class)->handle('order.paid', (object) ['source' => 'unknown-queue-failure-test']);
        $this->assertDatabaseCount('events', 1);
        $this->assertDatabaseCount('deliveries', 1);
        $this->assertDatabaseCount('delivery_outbox_messages', 1);
    }

    private function createEndpoint(string $name): string
    {
        $response = $this->postJson('/api/endpoints', [
            'name' => $name,
            'url' => 'https://example.test/webhooks/queue',
        ])->assertCreated();

        return (string) $response->json('data.id');
    }

    /**
     * @param  list<string>  $types
     */
    private function replaceSubscriptions(string $endpointId, array $types): void
    {
        $this->putJson("/api/endpoints/{$endpointId}/subscriptions", [
            'types' => $types,
        ])->assertOk();
    }

    /**
     * @return list<string>
     */
    private function deliveryIdsForEvent(string $eventId): array
    {
        return DB::table('deliveries')
            ->join('events', 'deliveries.event_id', '=', 'events.id')
            ->where('events.public_id', $eventId)
            ->orderBy('deliveries.id')
            ->pluck('deliveries.public_id')
            ->all();
    }

    private function postEvent(string $type): TestResponse
    {
        return $this->postJson('/api/events', [
            'type' => $type,
            'payload' => (object) ['order_id' => 'order_1001'],
        ]);
    }
}

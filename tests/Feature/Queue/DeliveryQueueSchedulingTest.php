<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Application\Delivery\DeliveryQueue;
use App\Application\Delivery\DeliveryQueueUnavailable;
use App\Application\Delivery\DeliveryRepository;
use App\Application\Event\CreateEvent;
use App\Application\Subscription\SubscriptionMatcher;
use App\Domain\Delivery\Delivery;
use App\Domain\Delivery\DeliveryId;
use App\Domain\Endpoint\EndpointId;
use App\Domain\Event\EventType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

final class DeliveryQueueSchedulingTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_event_without_matching_deliveries_does_not_request_queue_publication(): void
    {
        $queued = [];
        $this->replaceQueue($queued);

        $this->postEvent('order.paid')->assertCreated();

        self::assertSame([], $queued);
        $this->assertDatabaseCount('events', 1);
        $this->assertDatabaseCount('deliveries', 0);
    }

    public function test_each_committed_matching_delivery_requests_queue_publication(): void
    {
        $first = $this->createEndpoint('First queued endpoint');
        $second = $this->createEndpoint('Second queued endpoint');
        $this->replaceSubscriptions($first, ['order.paid']);
        $this->replaceSubscriptions($second, ['order.paid']);

        $queued = [];
        $this->replaceQueue($queued);

        $response = $this->postEvent('order.paid')->assertCreated();
        $eventId = (string) $response->json('data.id');

        self::assertCount(2, $queued);
        self::assertSame(
            $this->deliveryIdsForEvent($eventId),
            $queued,
        );
    }

    public function test_rollback_does_not_request_queue_publication(): void
    {
        $first = $this->createEndpoint('First rollback endpoint');
        $second = $this->createEndpoint('Second rollback endpoint');
        $queued = [];
        $this->replaceQueue($queued);

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

        $deliveries = app(DeliveryRepository::class);
        $this->app->instance(
            DeliveryRepository::class,
            new class($deliveries) implements DeliveryRepository
            {
                private int $calls = 0;

                public function __construct(
                    private DeliveryRepository $deliveries,
                ) {}

                public function createOrGet(Delivery $delivery): Delivery
                {
                    $this->calls++;

                    if ($this->calls === 2) {
                        throw new RuntimeException('Forced delivery persistence failure.');
                    }

                    return $this->deliveries->createOrGet($delivery);
                }

                public function all(): array
                {
                    return $this->deliveries->all();
                }

                public function find(string $id): ?Delivery
                {
                    return $this->deliveries->find($id);
                }
            },
        );

        try {
            app(CreateEvent::class)->handle('order.paid', (object) ['source' => 'queue-rollback-test']);
            self::fail('The second delivery persistence attempt must fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('Forced delivery persistence failure.', $exception->getMessage());
        }

        self::assertSame([], $queued);
        $this->assertDatabaseCount('events', 0);
        $this->assertDatabaseCount('deliveries', 0);
    }

    public function test_known_queue_publication_failure_keeps_the_committed_event_and_delivery_and_returns_201(): void
    {
        $endpointId = $this->createEndpoint('Unavailable queue endpoint');
        $this->replaceSubscriptions($endpointId, ['order.paid']);

        $this->app->instance(
            DeliveryQueue::class,
            new class implements DeliveryQueue
            {
                public function enqueue(DeliveryId $deliveryId): void
                {
                    throw new DeliveryQueueUnavailable($deliveryId, new RuntimeException('Redis is unavailable.'));
                }

                public function schedule(DeliveryId $deliveryId, \DateTimeImmutable $availableAt): void
                {
                    throw new DeliveryQueueUnavailable($deliveryId, new RuntimeException('Redis is unavailable.'));
                }
            },
        );

        $this->postEvent('order.paid')->assertCreated();

        $this->assertDatabaseCount('events', 1);
        $this->assertDatabaseCount('deliveries', 1);
        $this->assertDatabaseHas('deliveries', ['status' => 'pending']);
    }

    public function test_unknown_queue_errors_are_not_swallowed(): void
    {
        $endpointId = $this->createEndpoint('Unknown queue failure endpoint');
        $this->replaceSubscriptions($endpointId, ['order.paid']);

        $this->app->instance(
            DeliveryQueue::class,
            new class implements DeliveryQueue
            {
                public function enqueue(DeliveryId $deliveryId): void
                {
                    throw new RuntimeException('Unexpected queue programming failure.');
                }

                public function schedule(DeliveryId $deliveryId, \DateTimeImmutable $availableAt): void
                {
                    throw new RuntimeException('Unexpected queue programming failure.');
                }
            },
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected queue programming failure.');

        app(CreateEvent::class)->handle('order.paid', (object) ['source' => 'unknown-queue-failure-test']);
    }

    /**
     * @param  list<string>  $queued
     */
    private function replaceQueue(array &$queued): void
    {
        $this->app->instance(
            DeliveryQueue::class,
            new class($queued) implements DeliveryQueue
            {
                /**
                 * @param  list<string>  $queued
                 */
                public function __construct(array &$queued)
                {
                    $this->queued = &$queued;
                }

                /**
                 * @var list<string>
                 */
                private array $queued;

                public function enqueue(DeliveryId $deliveryId): void
                {
                    $this->queued[] = $deliveryId->toString();
                }

                public function schedule(DeliveryId $deliveryId, \DateTimeImmutable $availableAt): void {}
            },
        );
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

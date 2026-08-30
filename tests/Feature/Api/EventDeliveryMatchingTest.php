<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Application\Delivery\DeliverySnapshotCreator;
use App\Application\Event\CreateEvent;
use App\Application\Subscription\SubscriptionMatcher;
use App\Domain\Delivery\Delivery;
use App\Domain\Endpoint\EndpointId;
use App\Domain\Event\EventId;
use App\Domain\Event\EventType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

final class EventDeliveryMatchingTest extends TestCase
{
    use RefreshDatabase;

    public function test_events_without_matching_subscriptions_are_persisted_without_deliveries(): void
    {
        $created = $this->postEvent('order.paid');

        $created
            ->assertCreated()
            ->assertJsonPath('data.type', 'order.paid')
            ->assertJsonMissingPath('data.delivery_count')
            ->assertJsonMissingPath('data.deliveries');
        $this->assertDatabaseCount('events', 1);
        $this->assertDatabaseCount('deliveries', 0);
    }

    public function test_only_active_exactly_matching_endpoints_receive_one_pending_delivery_each(): void
    {
        $first = $this->createEndpoint('First matching endpoint');
        $second = $this->createEndpoint('Second matching endpoint');
        $disabled = $this->createEndpoint('Disabled matching endpoint', 'disabled');
        $differentType = $this->createEndpoint('Different type endpoint');
        $softDeleted = $this->createEndpoint('Soft deleted matching endpoint');

        $this->replaceSubscriptions($first, ['order.paid']);
        $this->replaceSubscriptions($second, ['order.paid']);
        $this->replaceSubscriptions($disabled, ['order.paid']);
        $this->replaceSubscriptions($differentType, ['invoice.paid']);
        $this->replaceSubscriptions($softDeleted, ['order.paid']);
        $this->deleteJson("/api/endpoints/{$softDeleted}")->assertNoContent();

        $created = $this->postEvent('order.paid')->assertCreated();
        $eventId = (string) $created->json('data.id');

        self::assertSame([$first, $second], $this->deliveryEndpointIds($eventId));
        self::assertSame(['pending', 'pending'], $this->deliveryStatuses($eventId));
        $this->assertDatabaseCount('deliveries', 2);
    }

    public function test_matcher_deduplicates_and_stably_orders_an_endpoint_with_multiple_subscriptions(): void
    {
        $first = $this->createEndpoint('First multi subscription endpoint');
        $second = $this->createEndpoint('Second multi subscription endpoint');

        $this->replaceSubscriptions($first, ['invoice.paid', 'order.paid']);
        $this->replaceSubscriptions($second, ['order.paid', 'user.created']);

        $matched = app(SubscriptionMatcher::class)->matchingActiveEndpointIds(EventType::fromString('order.paid'));

        self::assertSame([$first, $second], array_map(
            static fn (EndpointId $endpointId): string => $endpointId->toString(),
            $matched,
        ));

        $eventId = (string) $this->postEvent('order.paid')->assertCreated()->json('data.id');

        self::assertSame([$first, $second], $this->deliveryEndpointIds($eventId));
        $this->assertDatabaseCount('deliveries', 2);
    }

    public function test_subscription_changes_only_affect_future_events_and_never_backfill_history(): void
    {
        $first = $this->createEndpoint('Always subscribed endpoint');
        $second = $this->createEndpoint('Removed subscription endpoint');
        $third = $this->createEndpoint('Later subscription endpoint');

        $this->replaceSubscriptions($first, ['order.paid']);
        $this->replaceSubscriptions($second, ['order.paid']);
        $this->replaceSubscriptions($third, ['invoice.paid']);

        $firstEventId = (string) $this->postEvent('order.paid')->assertCreated()->json('data.id');
        self::assertSame([$first, $second], $this->deliveryEndpointIds($firstEventId));

        $this->replaceSubscriptions($second, []);
        $secondEventId = (string) $this->postEvent('order.paid')->assertCreated()->json('data.id');
        self::assertSame([$first], $this->deliveryEndpointIds($secondEventId));
        self::assertSame([$first, $second], $this->deliveryEndpointIds($firstEventId));

        $this->replaceSubscriptions($third, ['invoice.paid', 'order.paid']);
        $thirdEventId = (string) $this->postEvent('order.paid')->assertCreated()->json('data.id');
        self::assertSame([$first, $third], $this->deliveryEndpointIds($thirdEventId));
        self::assertSame([$first, $second], $this->deliveryEndpointIds($firstEventId));
    }

    public function test_identical_event_posts_create_distinct_events_and_deliveries(): void
    {
        $endpointId = $this->createEndpoint('Repeated event endpoint');
        $this->replaceSubscriptions($endpointId, ['order.paid']);

        $firstEventId = (string) $this->postEvent('order.paid')->assertCreated()->json('data.id');
        $secondEventId = (string) $this->postEvent('order.paid')->assertCreated()->json('data.id');

        self::assertNotSame($firstEventId, $secondEventId);
        self::assertSame([$endpointId], $this->deliveryEndpointIds($firstEventId));
        self::assertSame([$endpointId], $this->deliveryEndpointIds($secondEventId));
        $this->assertDatabaseCount('deliveries', 2);
    }

    public function test_an_unexpected_delivery_failure_rolls_back_the_event_and_every_delivery(): void
    {
        $first = $this->createEndpoint('Atomicity first endpoint');
        $second = $this->createEndpoint('Atomicity second endpoint');

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
            app(CreateEvent::class)->handle('order.paid', (object) ['source' => 'atomicity-test']);
            self::fail('The second delivery persistence attempt must fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('Forced delivery persistence failure.', $exception->getMessage());
        }

        $this->assertDatabaseCount('events', 0);
        $this->assertDatabaseCount('deliveries', 0);
    }

    private function createEndpoint(string $name, string $status = 'active'): string
    {
        $response = $this->postJson('/api/endpoints', [
            'name' => $name,
            'url' => 'https://example.test/webhooks/event-matching',
            'status' => $status,
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

    private function postEvent(string $type): TestResponse
    {
        return $this->postJson('/api/events', [
            'type' => $type,
            'payload' => (object) ['order_id' => 'order_1001'],
        ]);
    }

    /**
     * @return list<string>
     */
    private function deliveryEndpointIds(string $eventId): array
    {
        return DB::table('deliveries')
            ->join('events', 'deliveries.event_id', '=', 'events.id')
            ->join('endpoints', 'deliveries.endpoint_id', '=', 'endpoints.id')
            ->where('events.public_id', $eventId)
            ->orderBy('deliveries.id')
            ->pluck('endpoints.public_id')
            ->all();
    }

    /**
     * @return list<string>
     */
    private function deliveryStatuses(string $eventId): array
    {
        return DB::table('deliveries')
            ->join('events', 'deliveries.event_id', '=', 'events.id')
            ->where('events.public_id', $eventId)
            ->orderBy('deliveries.id')
            ->pluck('deliveries.status')
            ->all();
    }
}

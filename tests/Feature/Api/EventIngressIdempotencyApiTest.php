<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Application\Delivery\DeliveryExecutionIntent;
use App\Application\Delivery\DeliveryOutboxWriter;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use LogicException;
use stdClass;
use Tests\TestCase;

final class EventIngressIdempotencyApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_key_preserves_the_existing_create_new_event_behavior(): void
    {
        $endpointId = $this->createEndpoint('No key endpoint');
        $this->replaceSubscriptions($endpointId, ['order.paid']);

        $first = $this->postEvent('order.paid', (object) ['order_id' => 'same'])->assertCreated();
        $second = $this->postEvent('order.paid', (object) ['order_id' => 'same'])->assertCreated();

        self::assertNotSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('events', 2);
        $this->assertDatabaseCount('event_ingress_idempotencies', 0);
        $this->assertDatabaseCount('deliveries', 2);
        $this->assertDatabaseCount('delivery_outbox_messages', 2);
    }

    public function test_same_key_and_same_request_returns_the_committed_event_without_a_second_graph(): void
    {
        $endpointId = $this->createEndpoint('Idempotent endpoint');
        $this->replaceSubscriptions($endpointId, ['order.paid']);
        $key = 'event-ingress-same-request';

        $first = $this->postEvent('order.paid', (object) ['order_id' => '10001'], $key)->assertCreated();
        $second = $this->postEvent('order.paid', (object) ['order_id' => '10001'], $key)->assertOk();
        $eventId = (string) $first->json('data.id');

        self::assertSame($eventId, $second->json('data.id'));
        $this->assertDatabaseCount('events', 1);
        $this->assertDatabaseCount('event_ingress_idempotencies', 1);
        self::assertSame(1, DB::table('deliveries')->join('events', 'deliveries.event_id', '=', 'events.id')->where('events.public_id', $eventId)->count());
        self::assertSame(1, DB::table('delivery_outbox_messages')->count());
    }

    public function test_same_key_with_different_type_or_payload_returns_a_stable_conflict(): void
    {
        $key = 'event-ingress-conflict';
        $this->postEvent('order.paid', (object) ['amount' => 1], $key)->assertCreated();

        $this->postEvent('order.cancelled', (object) ['amount' => 1], $key)
            ->assertConflict()
            ->assertJsonPath('code', 'idempotency_key_conflict');
        $this->postEvent('order.paid', (object) ['amount' => 2], $key)
            ->assertConflict()
            ->assertJsonPath('code', 'idempotency_key_conflict');

        $this->assertDatabaseCount('events', 1);
        $this->assertDatabaseCount('event_ingress_idempotencies', 1);
        $this->assertDatabaseCount('deliveries', 0);
        $this->assertDatabaseCount('delivery_outbox_messages', 0);
    }

    public function test_object_order_is_idempotent_but_array_order_conflicts_and_empty_objects_keep_their_shape(): void
    {
        $key = 'event-ingress-canonical';
        $first = $this->postEvent('order.paid', (object) [
            'b' => 2,
            'a' => (object) ['z' => true, 'm' => [3, 2, 1]],
            'empty' => (object) [],
            'nested' => (object) ['empty' => (object) []],
        ], $key)->assertCreated();
        $same = $this->postEvent('order.paid', (object) [
            'nested' => (object) ['empty' => (object) []],
            'empty' => (object) [],
            'a' => (object) ['m' => [3, 2, 1], 'z' => true],
            'b' => 2,
        ], $key)->assertOk();
        $differentArrayOrder = $this->postEvent('order.paid', (object) [
            'a' => (object) ['m' => [1, 2, 3], 'z' => true],
            'b' => 2,
            'empty' => (object) [],
            'nested' => (object) ['empty' => (object) []],
        ], $key)->assertConflict();

        self::assertSame($first->json('data.id'), $same->json('data.id'));
        $body = json_decode($first->getContent(), false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $body->data->payload->empty);
        self::assertInstanceOf(stdClass::class, $body->data->payload->nested->empty);
        $differentArrayOrder->assertJsonPath('code', 'idempotency_key_conflict');
    }

    public function test_invalid_blank_and_overlong_keys_are_rejected_without_persistence(): void
    {
        foreach (['', 'contains space', str_repeat('a', 129)] as $key) {
            $this->postEvent('order.paid', (object) [], $key)
                ->assertUnprocessable()
                ->assertJsonPath('code', 'invalid_idempotency_key');
        }

        $this->assertDatabaseCount('events', 0);
        $this->assertDatabaseCount('event_ingress_idempotencies', 0);
    }

    public function test_raw_key_is_not_persisted_or_returned(): void
    {
        $rawKey = 'event-ingress-raw-key-fixture';
        $response = $this->postEvent('order.paid', (object) ['source' => 'no-key-leak'], $rawKey)->assertCreated();

        self::assertStringNotContainsString($rawKey, $response->getContent());
        foreach (['events', 'event_ingress_idempotencies', 'deliveries', 'delivery_outbox_messages'] as $table) {
            self::assertStringNotContainsString($rawKey, json_encode(DB::table($table)->get(), JSON_THROW_ON_ERROR));
        }
    }

    public function test_keyed_event_graph_and_binding_roll_back_together_when_outbox_persistence_fails(): void
    {
        $endpointId = $this->createEndpoint('Atomic keyed endpoint');
        $this->replaceSubscriptions($endpointId, ['order.paid']);
        $writer = app(DeliveryOutboxWriter::class);
        $this->app->instance(DeliveryOutboxWriter::class, new class implements DeliveryOutboxWriter
        {
            public function schedule(DeliveryExecutionIntent $intent, DateTimeImmutable $now): void
            {
                throw new LogicException('Forced event ingress outbox failure.');
            }
        });

        $key = 'event-ingress-atomic-rollback';
        $this->postEvent('order.paid', (object) ['source' => 'rollback'], $key)->assertStatus(500);
        $this->assertDatabaseCount('events', 0);
        $this->assertDatabaseCount('event_ingress_idempotencies', 0);
        $this->assertDatabaseCount('deliveries', 0);
        $this->assertDatabaseCount('delivery_outbox_messages', 0);

        $this->app->instance(DeliveryOutboxWriter::class, $writer);
        $this->postEvent('order.paid', (object) ['source' => 'rollback'], $key)->assertCreated();
        $this->assertDatabaseCount('events', 1);
        $this->assertDatabaseCount('event_ingress_idempotencies', 1);
        $this->assertDatabaseCount('deliveries', 1);
        $this->assertDatabaseCount('delivery_outbox_messages', 1);
    }

    public function test_existing_keyed_result_does_not_re_match_after_subscription_changes_but_a_new_key_uses_current_configuration(): void
    {
        $firstEndpointId = $this->createEndpoint('First subscription endpoint');
        $secondEndpointId = $this->createEndpoint('Second subscription endpoint');
        $this->replaceSubscriptions($firstEndpointId, ['order.paid']);

        $first = $this->postEvent('order.paid', (object) ['source' => 'stable'], 'event-ingress-stable')->assertCreated();
        $firstEventId = (string) $first->json('data.id');
        $this->replaceSubscriptions($firstEndpointId, []);
        $this->replaceSubscriptions($secondEndpointId, ['order.paid']);

        $same = $this->postEvent('order.paid', (object) ['source' => 'stable'], 'event-ingress-stable')->assertOk();
        $next = $this->postEvent('order.paid', (object) ['source' => 'stable'], 'event-ingress-current')->assertCreated();

        self::assertSame($firstEventId, $same->json('data.id'));
        self::assertSame([$firstEndpointId], $this->deliveryEndpointIds($firstEventId));
        self::assertSame([$secondEndpointId], $this->deliveryEndpointIds((string) $next->json('data.id')));
        $this->assertDatabaseCount('events', 2);
        $this->assertDatabaseCount('event_ingress_idempotencies', 2);
        $this->assertDatabaseCount('deliveries', 2);
        $this->assertDatabaseCount('delivery_outbox_messages', 2);
    }

    private function createEndpoint(string $name): string
    {
        return (string) $this->postJson('/api/endpoints', [
            'name' => $name,
            'url' => 'https://receiver.example/event-ingress',
        ])->assertCreated()->json('data.id');
    }

    /** @param list<string> $types */
    private function replaceSubscriptions(string $endpointId, array $types): void
    {
        $this->putJson("/api/endpoints/{$endpointId}/subscriptions", ['types' => $types])->assertOk();
    }

    private function postEvent(string $type, stdClass $payload, ?string $key = null): TestResponse
    {
        return $this->postJson('/api/events', [
            'type' => $type,
            'payload' => $payload,
        ], $key === null ? [] : ['Idempotency-Key' => $key]);
    }

    /** @return list<string> */
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
}

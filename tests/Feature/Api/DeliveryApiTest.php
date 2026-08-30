<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Application\Delivery\CreateDelivery;
use App\Application\Delivery\DeliveryData;
use App\Application\Endpoint\EndpointNotFound;
use App\Application\Event\EventNotFound;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DeliveryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_existing_event_and_endpoint_create_one_pending_delivery_idempotently(): void
    {
        $eventId = $this->createEvent('order.paid');
        $endpointId = $this->createEndpoint();

        $first = $this->createDelivery($eventId, $endpointId);
        $second = $this->createDelivery($eventId, $endpointId);

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $first->id,
        );
        self::assertSame($first->id, $second->id);
        self::assertSame('pending', $first->status);
        self::assertSame($eventId, $first->eventId);
        self::assertSame($endpointId, $first->endpointId);
        self::assertSame('https://example.test/webhooks/delivery', $first->targetUrl);
        $this->assertDatabaseCount('deliveries', 1);
    }

    public function test_the_database_unique_constraint_prevents_duplicate_event_and_endpoint_rows(): void
    {
        $eventId = $this->createEvent('order.paid');
        $endpointId = $this->createEndpoint();
        $this->createDelivery($eventId, $endpointId);

        /** @var int $eventInternalId */
        $eventInternalId = DB::table('events')->where('public_id', $eventId)->value('id');
        /** @var int $endpointInternalId */
        $endpointInternalId = DB::table('endpoints')->where('public_id', $endpointId)->value('id');

        $this->expectException(QueryException::class);

        DB::table('deliveries')->insert([
            'public_id' => '0f5c53d4-4957-4587-8685-c10b7a565f0b',
            'event_id' => $eventInternalId,
            'endpoint_id' => $endpointInternalId,
            'target_url' => 'https://example.test/webhooks/delivery',
            'status' => 'pending',
        ]);
    }

    public function test_missing_events_and_endpoints_have_business_not_found_semantics(): void
    {
        $endpointId = $this->createEndpoint();

        try {
            $this->createDelivery('c4c3a03f-7e0a-4057-9145-09b056fa4526', $endpointId);
            self::fail('Expected the missing Event to be rejected.');
        } catch (EventNotFound) {
            self::addToAssertionCount(1);
        }

        $eventId = $this->createEvent('order.paid');

        try {
            $this->createDelivery($eventId, 'c4c3a03f-7e0a-4057-9145-09b056fa4526');
            self::fail('Expected the missing Endpoint to be rejected.');
        } catch (EndpointNotFound) {
            self::addToAssertionCount(1);
        }
    }

    public function test_a_soft_deleted_endpoint_cannot_create_a_new_delivery(): void
    {
        $eventId = $this->createEvent('order.paid');
        $endpointId = $this->createEndpoint();

        $this->deleteJson("/api/endpoints/{$endpointId}")->assertNoContent();

        $this->expectException(EndpointNotFound::class);

        $this->createDelivery($eventId, $endpointId);
    }

    public function test_deliveries_are_read_only_and_history_survives_endpoint_soft_delete(): void
    {
        $firstEventId = $this->createEvent('order.paid');
        $firstEndpointId = $this->createEndpoint();
        $firstDelivery = $this->createDelivery($firstEventId, $firstEndpointId);

        $secondDelivery = $this->createDelivery(
            $this->createEvent('invoice.paid'),
            $this->createEndpoint('Invoice endpoint'),
        );

        $this->getJson('/api/deliveries')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $firstDelivery->id)
            ->assertJsonPath('data.1.id', $secondDelivery->id);

        $this->getJson("/api/deliveries/{$firstDelivery->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $firstDelivery->id)
            ->assertJsonPath('data.event_id', $firstEventId)
            ->assertJsonPath('data.endpoint_id', $firstEndpointId)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonStructure([
                'data' => ['id', 'event_id', 'endpoint_id', 'status', 'created_at', 'updated_at'],
            ]);

        $this->postJson('/api/deliveries', [])->assertMethodNotAllowed();
        $this->patchJson("/api/deliveries/{$firstDelivery->id}", [])->assertMethodNotAllowed();
        $this->deleteJson("/api/deliveries/{$firstDelivery->id}")->assertMethodNotAllowed();

        $this->deleteJson("/api/endpoints/{$firstEndpointId}")->assertNoContent();

        $this->getJson("/api/deliveries/{$firstDelivery->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $firstDelivery->id);
    }

    public function test_a_delivery_keeps_its_original_target_url_when_its_endpoint_changes(): void
    {
        $eventId = $this->createEvent('order.paid');
        $endpointId = $this->createEndpoint();
        $first = $this->createDelivery($eventId, $endpointId);

        $this->patchJson("/api/endpoints/{$endpointId}", [
            'url' => 'https://updated.example/webhooks/delivery',
        ])->assertOk();

        $sameDelivery = $this->createDelivery($eventId, $endpointId);

        self::assertSame($first->id, $sameDelivery->id);
        self::assertSame('https://example.test/webhooks/delivery', $sameDelivery->targetUrl);

        $future = $this->createDelivery($this->createEvent('invoice.paid'), $endpointId);
        self::assertSame('https://updated.example/webhooks/delivery', $future->targetUrl);
    }

    public function test_unknown_delivery_returns_not_found(): void
    {
        $this->getJson('/api/deliveries/c4c3a03f-7e0a-4057-9145-09b056fa4526')
            ->assertNotFound()
            ->assertJsonPath('message', 'Delivery not found.');
    }

    public function test_creating_an_event_automatically_creates_deliveries_for_matching_subscriptions(): void
    {
        $endpointId = $this->createEndpoint();

        $this->putJson("/api/endpoints/{$endpointId}/subscriptions", [
            'types' => ['order.paid'],
        ])->assertOk();

        $this->postJson('/api/events', [
            'type' => 'order.paid',
            'payload' => (object) ['order_id' => 'order_123'],
        ])->assertCreated();

        $this->assertDatabaseCount('deliveries', 1);
    }

    private function createDelivery(string $eventId, string $endpointId): DeliveryData
    {
        return app(CreateDelivery::class)->handle($eventId, $endpointId);
    }

    private function createEndpoint(string $name = 'Billing endpoint'): string
    {
        $response = $this->postJson('/api/endpoints', [
            'name' => $name,
            'url' => 'https://example.test/webhooks/delivery',
        ])->assertCreated();

        return (string) $response->json('data.id');
    }

    private function createEvent(string $type): string
    {
        $response = $this->postJson('/api/events', [
            'type' => $type,
            'payload' => (object) ['source' => 'delivery-test'],
        ])->assertCreated();

        return (string) $response->json('data.id');
    }
}

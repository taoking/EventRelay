<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EventReceiveApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_event_can_be_created_and_its_nested_payload_round_trips(): void
    {
        $payload = [
            'order' => [
                'id' => 'order_123',
                'total' => 4999,
            ],
            'items' => [
                ['sku' => 'sku_1', 'quantity' => 2],
            ],
        ];

        $created = $this->postJson('/api/events', [
            'type' => 'order.paid',
            'payload' => $payload,
        ]);

        $created
            ->assertCreated()
            ->assertJsonPath('data.type', 'order.paid')
            ->assertJsonPath('data.payload.order.id', 'order_123')
            ->assertJsonPath('data.payload.order.total', 4999)
            ->assertJsonPath('data.payload.items.0.sku', 'sku_1')
            ->assertJsonPath('data.payload.items.0.quantity', 2)
            ->assertJsonStructure([
                'data' => ['id', 'type', 'payload', 'created_at'],
            ]);
        $id = (string) $created->json('data.id');

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id,
        );

        $this->getJson("/api/events/{$id}")
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.type', 'order.paid')
            ->assertJsonPath('data.payload.order.id', 'order_123')
            ->assertJsonPath('data.payload.order.total', 4999)
            ->assertJsonPath('data.payload.items.0.sku', 'sku_1')
            ->assertJsonPath('data.payload.items.0.quantity', 2);
    }

    public function test_supported_event_types_and_an_empty_object_payload_are_accepted(): void
    {
        foreach (['order.paid', 'user_created', 'invoice-paid.v2'] as $type) {
            $this->postJson('/api/events', [
                'type' => $type,
                'payload' => (object) [],
            ])
                ->assertCreated()
                ->assertJsonPath('data.type', $type)
                ->assertJsonPath('data.payload', []);
        }
    }

    public function test_invalid_or_overlong_event_types_are_rejected(): void
    {
        foreach (['', ' ', 'Order.Paid', 'order paid', '.order', 'order.', str_repeat('a', 121)] as $type) {
            $this->postJson('/api/events', [
                'type' => $type,
                'payload' => (object) [],
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['type']);
        }
    }

    public function test_payload_must_be_present_and_a_json_object(): void
    {
        $this->postJson('/api/events', [
            'type' => 'order.paid',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payload']);

        foreach (['payload', 42, true, ['not', 'an', 'object']] as $payload) {
            $this->postJson('/api/events', [
                'type' => 'order.paid',
                'payload' => $payload,
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['payload']);
        }
    }

    public function test_events_are_listed_in_creation_order_and_have_no_mutation_api(): void
    {
        $first = $this->postJson('/api/events', [
            'type' => 'user.created',
            'payload' => (object) [],
        ])->assertCreated();
        $firstId = (string) $first->json('data.id');

        $second = $this->postJson('/api/events', [
            'type' => 'order.paid',
            'payload' => (object) [],
        ])->assertCreated();
        $secondId = (string) $second->json('data.id');

        $this->getJson('/api/events')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $firstId)
            ->assertJsonPath('data.1.id', $secondId);

        $this->patchJson("/api/events/{$firstId}", ['type' => 'order.refunded'])
            ->assertMethodNotAllowed();
        $this->deleteJson("/api/events/{$firstId}")
            ->assertMethodNotAllowed();
    }

    public function test_an_unknown_event_returns_not_found(): void
    {
        $this->getJson('/api/events/c4c3a03f-7e0a-4057-9145-09b056fa4526')
            ->assertNotFound()
            ->assertJsonPath('message', 'Event not found.');
    }
}

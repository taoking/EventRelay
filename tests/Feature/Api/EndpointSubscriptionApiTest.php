<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class EndpointSubscriptionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscriptions_start_empty_and_put_replaces_the_complete_set(): void
    {
        $id = $this->createEndpoint();

        $this->getJson("/api/endpoints/{$id}/subscriptions")
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'endpoint_id' => $id,
                    'types' => [],
                ],
            ]);

        $this->putJson("/api/endpoints/{$id}/subscriptions", [
            'types' => ['user.created', 'order.paid', 'user.created'],
        ])
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'endpoint_id' => $id,
                    'types' => ['order.paid', 'user.created'],
                ],
            ]);
        $this->assertDatabaseCount('endpoint_subscriptions', 2);

        $this->getJson("/api/endpoints/{$id}/subscriptions")
            ->assertOk()
            ->assertJsonPath('data.types', ['order.paid', 'user.created']);

        $this->putJson("/api/endpoints/{$id}/subscriptions", [
            'types' => ['invoice-paid.v2'],
        ])
            ->assertOk()
            ->assertJsonPath('data.types', ['invoice-paid.v2']);
        $this->assertDatabaseCount('endpoint_subscriptions', 1);

        $this->putJson("/api/endpoints/{$id}/subscriptions", ['types' => []])
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'endpoint_id' => $id,
                    'types' => [],
                ],
            ]);
        $this->assertDatabaseCount('endpoint_subscriptions', 0);
    }

    public function test_subscription_types_are_validated_by_the_shared_event_type_contract(): void
    {
        $id = $this->createEndpoint();

        foreach (['', 'Order.Paid', 'order paid', '.order', 'order.', str_repeat('a', 121)] as $type) {
            $this->putJson("/api/endpoints/{$id}/subscriptions", [
                'types' => [$type],
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['types.0']);
        }

        $this->putJson("/api/endpoints/{$id}/subscriptions", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['types']);

        $this->putJson("/api/endpoints/{$id}/subscriptions", ['types' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['types']);

        $this->putJson("/api/endpoints/{$id}/subscriptions", ['types' => ['order.paid', 42]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['types.1']);
    }

    public function test_nonexistent_and_soft_deleted_endpoints_cannot_be_read_or_replaced(): void
    {
        $unknownId = 'c4c3a03f-7e0a-4057-9145-09b056fa4526';

        $this->getJson("/api/endpoints/{$unknownId}/subscriptions")
            ->assertNotFound()
            ->assertJsonPath('message', 'Endpoint not found.');
        $this->putJson("/api/endpoints/{$unknownId}/subscriptions", ['types' => []])
            ->assertNotFound()
            ->assertJsonPath('message', 'Endpoint not found.');

        $id = $this->createEndpoint();
        $this->deleteJson("/api/endpoints/{$id}")->assertNoContent();

        $this->getJson("/api/endpoints/{$id}/subscriptions")->assertNotFound();
        $this->putJson("/api/endpoints/{$id}/subscriptions", ['types' => ['order.paid']])
            ->assertNotFound();
    }

    public function test_the_database_prevents_duplicate_endpoint_and_event_type_rows(): void
    {
        $id = $this->createEndpoint();

        $this->putJson("/api/endpoints/{$id}/subscriptions", [
            'types' => ['order.paid'],
        ])->assertOk();

        /** @var int $endpointId */
        $endpointId = DB::table('endpoints')->where('public_id', $id)->value('id');

        $this->expectException(QueryException::class);

        DB::table('endpoint_subscriptions')->insert([
            'endpoint_id' => $endpointId,
            'event_type' => 'order.paid',
        ]);
    }

    private function createEndpoint(): string
    {
        $response = $this->postJson('/api/endpoints', [
            'name' => 'Billing events',
            'url' => 'https://example.test/webhooks/billing',
        ])->assertCreated();

        return (string) $response->json('data.id');
    }
}

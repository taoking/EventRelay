<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EndpointCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_endpoint_can_be_created_with_a_public_uuid(): void
    {
        $response = $this->postJson('/api/endpoints', [
            'name' => 'Billing events',
            'url' => 'https://example.test/webhooks/billing',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', 'Billing events')
            ->assertJsonPath('data.url', 'https://example.test/webhooks/billing')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonStructure([
                'data' => ['id', 'name', 'url', 'status', 'created_at', 'updated_at'],
            ]);

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            (string) $response->json('data.id'),
        );
    }

    public function test_create_validates_required_fields_url_scheme_and_boundaries(): void
    {
        $this->postJson('/api/endpoints', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'url']);

        $this->postJson('/api/endpoints', [
            'name' => 'Billing events',
            'url' => 'ftp://example.test/webhooks/billing',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['url']);

        $this->postJson('/api/endpoints', [
            'name' => str_repeat('a', 120),
            'url' => 'https://example.test/webhooks/billing',
        ])->assertCreated();

        $this->postJson('/api/endpoints', [
            'name' => str_repeat('a', 121),
            'url' => 'https://example.test/webhooks/billing',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $maximumUrl = 'https://example.test/'.str_repeat('a', 2027);

        $this->postJson('/api/endpoints', [
            'name' => 'Longest URL',
            'url' => $maximumUrl,
        ])->assertCreated();

        $this->postJson('/api/endpoints', [
            'name' => 'Overlong URL',
            'url' => $maximumUrl.'a',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['url']);
    }

    public function test_endpoints_can_be_listed_shown_and_partially_updated(): void
    {
        $created = $this->postJson('/api/endpoints', [
            'name' => 'Billing events',
            'url' => 'https://example.test/webhooks/billing',
            'status' => 'active',
        ])->assertCreated();
        $id = (string) $created->json('data.id');

        $this->postJson('/api/endpoints', [
            'name' => 'Audit events',
            'url' => 'https://example.test/webhooks/audit',
            'status' => 'disabled',
        ])->assertCreated();

        $this->getJson('/api/endpoints')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $id);

        $this->getJson("/api/endpoints/{$id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Billing events');

        $this->patchJson("/api/endpoints/{$id}", [
            'name' => 'Billing event notifications',
            'url' => 'http://example.test/webhooks/billing-v2',
            'status' => 'disabled',
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.name', 'Billing event notifications')
            ->assertJsonPath('data.url', 'http://example.test/webhooks/billing-v2')
            ->assertJsonPath('data.status', 'disabled');
    }

    public function test_unknown_endpoints_return_not_found(): void
    {
        $unknownId = 'c4c3a03f-7e0a-4057-9145-09b056fa4526';

        $this->getJson("/api/endpoints/{$unknownId}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Endpoint not found.');

        $this->patchJson("/api/endpoints/{$unknownId}", ['status' => 'disabled'])
            ->assertNotFound();

        $this->deleteJson("/api/endpoints/{$unknownId}")
            ->assertNotFound();
    }

    public function test_deleting_an_endpoint_soft_deletes_it_from_the_api(): void
    {
        $created = $this->postJson('/api/endpoints', [
            'name' => 'Billing events',
            'url' => 'https://example.test/webhooks/billing',
        ])->assertCreated();
        $id = (string) $created->json('data.id');

        $this->deleteJson("/api/endpoints/{$id}")->assertNoContent();

        $this->getJson("/api/endpoints/{$id}")->assertNotFound();
        $this->getJson('/api/endpoints')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSoftDeleted('endpoints', ['public_id' => $id]);
    }
}

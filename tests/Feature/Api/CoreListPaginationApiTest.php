<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CoreListPaginationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_core_lists_are_bounded_by_default_and_keep_their_existing_item_shape(): void
    {
        for ($index = 0; $index < 125; $index++) {
            $this->insertEvent("event.{$index}");
        }
        for ($index = 0; $index < 101; $index++) {
            $this->insertDelivery("delivery.{$index}");
            $this->insertEndpoint("endpoint.{$index}");
        }

        $this->getJson('/api/events')
            ->assertOk()
            ->assertJsonCount(50, 'data')
            ->assertJsonPath('meta.limit', 50)
            ->assertJsonStructure(['data' => [['id', 'type', 'payload', 'created_at']], 'meta' => ['limit', 'next_cursor']])
            ->assertJsonMissingPath('meta.total')
            ->assertJsonMissingPath('meta.page');

        $this->getJson('/api/deliveries')
            ->assertOk()
            ->assertJsonCount(50, 'data')
            ->assertJsonPath('meta.limit', 50)
            ->assertJsonStructure(['data' => [['id', 'event_id', 'endpoint_id', 'replay_of_delivery_id', 'status', 'created_at', 'updated_at']], 'meta' => ['limit', 'next_cursor']]);

        $this->getJson('/api/endpoints')
            ->assertOk()
            ->assertJsonCount(50, 'data')
            ->assertJsonPath('meta.limit', 50)
            ->assertJsonStructure(['data' => [['id', 'name', 'url', 'status', 'created_at', 'updated_at']], 'meta' => ['limit', 'next_cursor']]);
    }

    public function test_limit_accepts_real_query_strings_between_one_and_one_hundred_and_rejects_invalid_values(): void
    {
        for ($index = 0; $index < 101; $index++) {
            $this->insertEvent("limit.{$index}");
        }

        $this->getJson('/api/events?limit=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.limit', 1);
        $this->getJson('/api/events?limit=50')->assertOk()->assertJsonCount(50, 'data')->assertJsonPath('meta.limit', 50);
        $this->getJson('/api/events?limit=100')->assertOk()->assertJsonCount(100, 'data')->assertJsonPath('meta.limit', 100);

        foreach (['0', '101', '-1', 'abc', '1.5', '01'] as $value) {
            $this->getJson('/api/events?limit='.$value)
                ->assertUnprocessable()
                ->assertJsonPath('code', 'invalid_pagination_limit');
        }
        $this->getJson('/api/events?limit[]=1')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_pagination_limit');
    }

    public function test_events_use_a_snapshot_upper_boundary_and_the_cursor_after_key_is_the_last_returned_row(): void
    {
        $ids = [];
        for ($index = 0; $index < 3; $index++) {
            $ids[] = $this->insertEvent("snapshot.{$index}", '2026-09-20 12:00:00');
        }

        $first = $this->getJson('/api/events?limit=2')->assertOk();
        self::assertSame(array_slice($ids, 0, 2), array_column($first->json('data'), 'id'));
        $cursor = $this->requiredCursor($first->json('meta.next_cursor'));
        self::assertStringNotContainsString($ids[0], $cursor);

        $newId = $this->insertEvent('snapshot.new', '2026-09-20 12:00:00');
        $second = $this->getJson('/api/events?'.http_build_query(['limit' => 2, 'cursor' => $cursor]))->assertOk();
        self::assertSame([$ids[2]], array_column($second->json('data'), 'id'));
        self::assertSame(null, $second->json('meta.next_cursor'));
        self::assertNotContains($newId, array_column($second->json('data'), 'id'));
    }

    public function test_cursor_is_authenticated_versioned_resource_bound_and_does_not_leak_its_keyset(): void
    {
        $this->insertEvent('cursor.one');
        $this->insertEvent('cursor.two');
        $this->insertDelivery('cursor.delivery.one');
        $this->insertDelivery('cursor.delivery.two');
        $this->insertEndpoint('cursor.endpoint.one');
        $this->insertEndpoint('cursor.endpoint.two');

        $eventCursor = $this->requiredCursor($this->getJson('/api/events?limit=1')->assertOk()->json('meta.next_cursor'));
        $deliveryCursor = $this->requiredCursor($this->getJson('/api/deliveries?limit=1')->assertOk()->json('meta.next_cursor'));
        $endpointCursor = $this->requiredCursor($this->getJson('/api/endpoints?limit=1')->assertOk()->json('meta.next_cursor'));
        $this->getJson('/api/deliveries?'.http_build_query(['limit' => 1, 'cursor' => $deliveryCursor]))->assertOk();
        $this->getJson('/api/endpoints?'.http_build_query(['limit' => 1, 'cursor' => $endpointCursor]))->assertOk();

        foreach ([
            'tampered' => $this->tamper($eventCursor),
            'truncated' => substr($eventCursor, 0, -8),
            'garbage' => 'not-a-cursor',
            'unknown-version' => $this->encryptedCursor(['v' => 2, 'resource' => 'events', 'after' => 1, 'upper' => 2]),
            'invalid-key-type' => $this->encryptedCursor(['v' => 1, 'resource' => 'events', 'after' => '1', 'upper' => 2]),
        ] as $name => $invalid) {
            $response = $this->getJson('/api/events?'.http_build_query(['cursor' => $invalid]));
            self::assertSame(422, $response->status(), "{$name} cursor must be rejected.");
            $response->assertJsonPath('code', 'invalid_pagination_cursor');
            $body = (string) $response->getContent();
            self::assertStringNotContainsString('after', $body);
            self::assertStringNotContainsString('upper', $body);
        }

        $this->getJson('/api/deliveries?'.http_build_query(['cursor' => $eventCursor]))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_pagination_cursor');
        $this->getJson('/api/endpoints?'.http_build_query(['cursor' => $deliveryCursor]))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_pagination_cursor');
        $this->getJson('/api/endpoints?cursor[]=invalid')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_pagination_cursor');
    }

    public function test_delivery_and_endpoint_pages_use_fixed_bounded_query_counts_without_n_plus_one(): void
    {
        for ($index = 0; $index < 3; $index++) {
            $this->insertDelivery("query.delivery.{$index}");
            $this->insertEndpoint("query.endpoint.{$index}");
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/deliveries?limit=2')->assertOk()->assertJsonCount(2, 'data');
        $deliveryQueries = DB::getQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/endpoints?limit=2')->assertOk()->assertJsonCount(2, 'data');
        $endpointQueries = DB::getQueryLog();
        DB::disableQueryLog();

        self::assertCount(2, $deliveryQueries);
        self::assertCount(2, $endpointQueries);
        self::assertStringContainsString('join', strtolower($deliveryQueries[1]['query']));
        self::assertStringNotContainsString('offset', strtolower($deliveryQueries[1]['query']));
        self::assertStringNotContainsString('count(', strtolower($deliveryQueries[1]['query']));
    }

    public function test_next_pages_issue_one_bounded_keyset_query_for_every_core_resource(): void
    {
        for ($index = 0; $index < 3; $index++) {
            $this->insertEvent("next.event.{$index}");
            $this->insertDelivery("next.delivery.{$index}");
            $this->insertEndpoint("next.endpoint.{$index}");
        }

        $eventCursor = $this->requiredCursor($this->getJson('/api/events?limit=1')->assertOk()->json('meta.next_cursor'));
        $deliveryCursor = $this->requiredCursor($this->getJson('/api/deliveries?limit=1')->assertOk()->json('meta.next_cursor'));
        $endpointCursor = $this->requiredCursor($this->getJson('/api/endpoints?limit=1')->assertOk()->json('meta.next_cursor'));

        foreach ([
            ['/api/events', $eventCursor],
            ['/api/deliveries', $deliveryCursor],
            ['/api/endpoints', $endpointCursor],
        ] as [$path, $cursor]) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($path.'?'.http_build_query(['limit' => 1, 'cursor' => $cursor]))->assertOk()->assertJsonCount(1, 'data');
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            self::assertCount(1, $queries);
            self::assertStringNotContainsString('offset', strtolower($queries[0]['query']));
            self::assertStringNotContainsString('count(', strtolower($queries[0]['query']));
        }
    }

    public function test_endpoints_exclude_new_rows_and_hide_rows_soft_deleted_between_pages_without_duplicates(): void
    {
        $ids = [];
        for ($index = 0; $index < 5; $index++) {
            $ids[] = $this->insertEndpoint("endpoint.snapshot.{$index}");
        }

        $first = $this->getJson('/api/endpoints?limit=2')->assertOk();
        $cursor = $this->requiredCursor($first->json('meta.next_cursor'));
        $newId = $this->insertEndpoint('endpoint.snapshot.new');
        $this->deleteJson('/api/endpoints/'.$ids[3])->assertNoContent();

        $second = $this->getJson('/api/endpoints?'.http_build_query(['limit' => 10, 'cursor' => $cursor]))->assertOk();
        $actual = array_column($second->json('data'), 'id');
        self::assertSame([$ids[2], $ids[4]], $actual);
        self::assertNotContains($newId, $actual);
        self::assertNotContains($ids[3], $actual);
        self::assertEmpty(array_intersect(array_column($first->json('data'), 'id'), $actual));
    }

    /** @param array<string, mixed> $payload */
    private function encryptedCursor(array $payload): string
    {
        return app(Encrypter::class)->encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function tamper(string $cursor): string
    {
        return ($cursor[0] === 'A' ? 'B' : 'A').substr($cursor, 1);
    }

    private function requiredCursor(mixed $cursor): string
    {
        if (! is_string($cursor) || $cursor === '') {
            self::fail('Expected a non-empty next cursor.');
        }

        return $cursor;
    }

    private function insertEvent(string $type, string $timestamp = '2026-09-20 12:00:00'): string
    {
        $id = (string) Str::uuid();
        DB::table('events')->insert([
            'public_id' => $id,
            'type' => $type,
            'payload' => '{}',
            'created_at' => $timestamp,
        ]);

        return $id;
    }

    private function insertEndpoint(string $name, string $timestamp = '2026-09-20 12:00:00'): string
    {
        $id = (string) Str::uuid();
        DB::table('endpoints')->insert([
            'public_id' => $id,
            'name' => $name,
            'url' => 'https://receiver.example/'.$id,
            'status' => 'active',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $id;
    }

    private function insertDelivery(string $type, string $timestamp = '2026-09-20 12:00:00'): string
    {
        $eventId = $this->insertEvent($type, $timestamp);
        $endpointId = $this->insertEndpoint('Delivery '.$type, $timestamp);
        $event = DB::table('events')->where('public_id', $eventId)->value('id');
        $endpoint = DB::table('endpoints')->where('public_id', $endpointId)->value('id');
        $deliveryId = (string) Str::uuid();
        DB::table('deliveries')->insert([
            'public_id' => $deliveryId,
            'event_id' => $event,
            'endpoint_id' => $endpoint,
            'creation_key' => 'primary',
            'target_url' => 'https://receiver.example/'.$deliveryId,
            'status' => 'pending',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $deliveryId;
    }
}

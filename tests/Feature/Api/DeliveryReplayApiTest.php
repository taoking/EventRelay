<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Application\Clock\Clock;
use App\Application\Delivery\CreateDelivery;
use App\Application\Delivery\DeliveryExecutionIntent;
use App\Application\Delivery\DeliveryOutboxWriter;
use App\Application\Delivery\DeliveryReplayCreator;
use App\Application\Delivery\ProcessPendingDelivery;
use App\Application\Delivery\WebhookRequest;
use App\Application\Delivery\WebhookResponse;
use App\Application\Delivery\WebhookTarget;
use App\Application\Delivery\WebhookTargetResolver;
use App\Application\Delivery\WebhookTransport;
use App\Domain\Delivery\DeliveryId;
use App\Infrastructure\Persistence\Eloquent\EloquentDeliveryRepository;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FrozenClock;
use Tests\TestCase;

final class DeliveryReplayApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_delivery_replay_creates_a_new_delivery_and_initial_outbox_without_mutating_source(): void
    {
        [$eventId, $endpointId, $sourceId] = $this->failedSource();
        $this->patchJson("/api/endpoints/{$endpointId}", ['url' => 'https://fixed.example/webhook'])->assertOk();

        $response = $this->postJson("/api/deliveries/{$sourceId}/replay", [], ['Idempotency-Key' => 'fixture-key-A'])
            ->assertCreated()
            ->assertJsonPath('data.event_id', $eventId)
            ->assertJsonPath('data.endpoint_id', $endpointId)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.replay_of_delivery_id', $sourceId);
        $replayId = (string) $response->json('data.id');

        self::assertNotSame($sourceId, $replayId);
        $this->assertDatabaseHas('deliveries', ['public_id' => $sourceId, 'status' => 'failed', 'replay_of_delivery_id' => null]);
        $this->assertDatabaseHas('deliveries', ['public_id' => $replayId, 'target_url' => 'https://fixed.example/webhook', 'status' => 'pending']);
        $this->assertDatabaseHas('delivery_outbox_messages', ['dedupe_key' => "delivery:{$replayId}:attempt:1", 'status' => 'pending']);
        self::assertSame(0, DB::table('delivery_attempts')->where('delivery_id', DB::table('deliveries')->where('public_id', $replayId)->value('id'))->count());
    }

    public function test_same_source_and_key_returns_existing_replay_but_different_keys_create_new_replays(): void
    {
        [, , $sourceId] = $this->failedSource();
        $first = $this->postJson("/api/deliveries/{$sourceId}/replay", [], ['Idempotency-Key' => 'fixture-key-A'])->assertCreated();
        $same = $this->postJson("/api/deliveries/{$sourceId}/replay", [], ['Idempotency-Key' => 'fixture-key-A'])->assertOk();
        $other = $this->postJson("/api/deliveries/{$sourceId}/replay", [], ['Idempotency-Key' => 'fixture-key-B'])->assertCreated();

        self::assertSame($first->json('data.id'), $same->json('data.id'));
        self::assertNotSame($first->json('data.id'), $other->json('data.id'));
        self::assertSame(3, DB::table('deliveries')->count());
        self::assertStringNotContainsString('fixture-key-A', json_encode(DB::table('deliveries')->get(), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('fixture-key-A', json_encode(DB::table('delivery_outbox_messages')->get(), JSON_THROW_ON_ERROR));
    }

    public function test_same_key_returns_the_committed_replay_after_endpoint_is_disabled_but_a_new_key_is_rejected(): void
    {
        [, $endpointId, $sourceId] = $this->failedSource();
        $replayId = (string) $this->postJson("/api/deliveries/{$sourceId}/replay", [], ['Idempotency-Key' => 'disabled-existing'])
            ->assertCreated()
            ->json('data.id');

        $this->patchJson("/api/endpoints/{$endpointId}", ['status' => 'disabled'])->assertOk();

        $this->postJson("/api/deliveries/{$sourceId}/replay", [], ['Idempotency-Key' => 'disabled-existing'])
            ->assertOk()
            ->assertJsonPath('data.id', $replayId);
        $this->postJson("/api/deliveries/{$sourceId}/replay", [], ['Idempotency-Key' => 'disabled-new'])
            ->assertConflict()
            ->assertJsonPath('code', 'replay_endpoint_unavailable');

        self::assertSame(1, DB::table('deliveries')->whereNotNull('replay_of_delivery_id')->count());
        self::assertSame(1, DB::table('delivery_outbox_messages')
            ->where('dedupe_key', "delivery:{$replayId}:attempt:1")
            ->count());
    }

    public function test_same_key_returns_the_committed_replay_after_endpoint_is_soft_deleted_but_a_new_key_is_rejected(): void
    {
        [, $endpointId, $sourceId] = $this->failedSource();
        $replayId = (string) $this->postJson("/api/deliveries/{$sourceId}/replay", [], ['Idempotency-Key' => 'deleted-existing'])
            ->assertCreated()
            ->json('data.id');

        $this->deleteJson("/api/endpoints/{$endpointId}")->assertNoContent();

        $this->postJson("/api/deliveries/{$sourceId}/replay", [], ['Idempotency-Key' => 'deleted-existing'])
            ->assertOk()
            ->assertJsonPath('data.id', $replayId);
        $this->postJson("/api/deliveries/{$sourceId}/replay", [], ['Idempotency-Key' => 'deleted-new'])
            ->assertConflict()
            ->assertJsonPath('code', 'replay_endpoint_unavailable');

        self::assertSame(1, DB::table('deliveries')->whereNotNull('replay_of_delivery_id')->count());
        self::assertSame(1, DB::table('delivery_outbox_messages')
            ->where('dedupe_key', "delivery:{$replayId}:attempt:1")
            ->count());
    }

    public function test_same_key_preserves_the_original_endpoint_snapshot_after_configuration_changes(): void
    {
        $endpointId = (string) $this->postJson('/api/endpoints', [
            'name' => 'Replay idempotency snapshot endpoint', 'url' => 'https://first.example/webhook',
        ])->assertCreated()->json('data.id');
        $keyOne = $this->postJson("/api/endpoints/{$endpointId}/signing-secret")->assertCreated()->json('data.key_id');
        $eventId = (string) $this->postJson('/api/events', [
            'type' => 'order.paid', 'payload' => (object) [],
        ])->assertCreated()->json('data.id');
        $sourceId = app(CreateDelivery::class)->handle($eventId, $endpointId)->id;
        DB::table('deliveries')->where('public_id', $sourceId)->update(['status' => 'failed']);

        $firstReplayId = (string) $this->postJson("/api/deliveries/{$sourceId}/replay", [], ['Idempotency-Key' => 'config-existing'])
            ->assertCreated()
            ->json('data.id');

        $this->patchJson("/api/endpoints/{$endpointId}", ['url' => 'https://second.example/webhook'])->assertOk();
        $keyTwo = $this->postJson("/api/endpoints/{$endpointId}/signing-secret")->assertCreated()->json('data.key_id');

        $this->postJson("/api/deliveries/{$sourceId}/replay", [], ['Idempotency-Key' => 'config-existing'])
            ->assertOk()
            ->assertJsonPath('data.id', $firstReplayId);
        $secondReplayId = (string) $this->postJson("/api/deliveries/{$sourceId}/replay", [], ['Idempotency-Key' => 'config-new'])
            ->assertCreated()
            ->json('data.id');

        $firstReplay = DB::table('deliveries')->where('public_id', $firstReplayId)->first();
        $secondReplay = DB::table('deliveries')->where('public_id', $secondReplayId)->first();
        self::assertNotNull($firstReplay);
        self::assertNotNull($secondReplay);
        self::assertSame('https://first.example/webhook', $firstReplay->target_url);
        self::assertSame('https://second.example/webhook', $secondReplay->target_url);
        self::assertSame($keyOne, DB::table('endpoint_signing_secrets')->where('id', $firstReplay->signing_secret_id)->value('public_id'));
        self::assertSame($keyTwo, DB::table('endpoint_signing_secrets')->where('id', $secondReplay->signing_secret_id)->value('public_id'));
    }

    public function test_the_same_key_on_different_sources_creates_distinct_replays(): void
    {
        [, , $firstSource] = $this->failedSource();
        [, , $secondSource] = $this->failedSource();

        $firstReplay = (string) $this->postJson("/api/deliveries/{$firstSource}/replay", [], ['Idempotency-Key' => 'same-key-different-source'])
            ->assertCreated()
            ->json('data.id');
        $secondReplay = (string) $this->postJson("/api/deliveries/{$secondSource}/replay", [], ['Idempotency-Key' => 'same-key-different-source'])
            ->assertCreated()
            ->json('data.id');

        self::assertNotSame($firstReplay, $secondReplay);
        self::assertSame(2, DB::table('deliveries')->whereNotNull('replay_of_delivery_id')->count());
    }

    public function test_replay_rejects_non_failed_or_unavailable_endpoint_and_invalid_keys(): void
    {
        foreach (['pending', 'processing', 'retry_scheduled', 'succeeded'] as $status) {
            [, , $deliveryId] = $this->source($status);
            $this->postJson("/api/deliveries/{$deliveryId}/replay", [], ['Idempotency-Key' => 'valid'])->assertConflict()->assertJsonPath('code', 'delivery_not_replayable');
        }
        [, $endpointId, $pending] = $this->source('pending');
        $this->postJson("/api/deliveries/{$pending}/replay")->assertUnprocessable()->assertJsonPath('code', 'invalid_idempotency_key');
        $this->postJson("/api/deliveries/{$pending}/replay", [], ['Idempotency-Key' => 'bad key'])->assertUnprocessable()->assertJsonPath('code', 'invalid_idempotency_key');
        $this->postJson("/api/deliveries/{$pending}/replay", [], ['Idempotency-Key' => str_repeat('a', 129)])->assertUnprocessable()->assertJsonPath('code', 'invalid_idempotency_key');

        [, , $failed] = $this->failedSource($endpointId);
        $this->patchJson("/api/endpoints/{$endpointId}", ['status' => 'disabled'])->assertOk();
        $this->postJson("/api/deliveries/{$failed}/replay", [], ['Idempotency-Key' => 'valid'])->assertConflict()->assertJsonPath('code', 'replay_endpoint_unavailable');

        $this->postJson('/api/deliveries/c4c3a03f-7e0a-4057-9145-09b056fa4526/replay', [], ['Idempotency-Key' => 'valid'])
            ->assertNotFound()
            ->assertJsonPath('code', 'delivery_not_found');
    }

    public function test_replay_rejects_a_soft_deleted_endpoint_without_reusing_the_source_snapshot(): void
    {
        [, $endpointId, $sourceId] = $this->failedSource();
        $this->deleteJson("/api/endpoints/{$endpointId}")->assertNoContent();

        $this->postJson("/api/deliveries/{$sourceId}/replay", [], ['Idempotency-Key' => 'deleted-endpoint'])
            ->assertConflict()
            ->assertJsonPath('code', 'replay_endpoint_unavailable');
        self::assertSame(1, DB::table('deliveries')->count());
    }

    public function test_automatic_create_delivery_still_returns_primary_after_replays_exist(): void
    {
        [$eventId, $endpointId, $sourceId] = $this->failedSource();
        $this->postJson("/api/deliveries/{$sourceId}/replay", [], ['Idempotency-Key' => 'one'])->assertCreated();
        $this->postJson("/api/deliveries/{$sourceId}/replay", [], ['Idempotency-Key' => 'two'])->assertCreated();

        self::assertSame($sourceId, app(CreateDelivery::class)->handle($eventId, $endpointId)->id);
    }

    public function test_replay_and_its_initial_outbox_are_rolled_back_together_when_intent_persistence_fails(): void
    {
        [, , $sourceId] = $this->failedSource();
        $this->app->instance(DeliveryReplayCreator::class, new EloquentDeliveryRepository(new class implements DeliveryOutboxWriter
        {
            public function schedule(DeliveryExecutionIntent $intent, DateTimeImmutable $now): void
            {
                throw new \LogicException('Replay outbox persistence failed.');
            }
        }));

        $this->postJson("/api/deliveries/{$sourceId}/replay", [], ['Idempotency-Key' => 'rollback-fixture'])
            ->assertStatus(500);

        self::assertSame(1, DB::table('deliveries')->count());
        self::assertSame(0, DB::table('delivery_outbox_messages')->count());
        $this->assertDatabaseHas('deliveries', ['public_id' => $sourceId, 'status' => 'failed']);
    }

    public function test_replay_snapshots_the_current_signing_key_and_keeps_it_for_its_own_retry(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-09-03T12:00:00+00:00'));
        $this->app->instance(Clock::class, $clock);
        $endpointId = (string) $this->postJson('/api/endpoints', [
            'name' => 'Replay signing endpoint', 'url' => 'https://fixed.example/signed',
        ])->assertCreated()->json('data.id');
        $keyOne = $this->postJson("/api/endpoints/{$endpointId}/signing-secret")->assertCreated()->json('data');
        $eventId = (string) $this->postJson('/api/events', [
            'type' => 'order.paid', 'payload' => (object) [],
        ])->assertCreated()->json('data.id');
        $sourceId = app(CreateDelivery::class)->handle($eventId, $endpointId)->id;
        DB::table('deliveries')->where('public_id', $sourceId)->update(['status' => 'failed']);
        $keyTwo = $this->postJson("/api/endpoints/{$endpointId}/signing-secret")->assertCreated()->json('data');

        $replayId = (string) $this->postJson("/api/deliveries/{$sourceId}/replay", [], ['Idempotency-Key' => 'replay-frozen-key'])
            ->assertCreated()
            ->json('data.id');
        $keyThree = $this->postJson("/api/endpoints/{$endpointId}/signing-secret")->assertCreated()->json('data');
        $transport = new class implements WebhookTransport
        {
            /** @var list<WebhookRequest> */
            public array $requests = [];

            public function send(WebhookTarget $target, WebhookRequest $request): WebhookResponse
            {
                $this->requests[] = $request;

                return count($this->requests) === 1 ? new WebhookResponse(500, 1) : new WebhookResponse(200, 1);
            }
        };
        $this->app->instance(WebhookTargetResolver::class, new class implements WebhookTargetResolver
        {
            public function resolve(string $url): WebhookTarget
            {
                return new WebhookTarget($url, 'fixed.example', 443, '1.1.1.1');
            }
        });
        $this->app->instance(WebhookTransport::class, $transport);

        app(ProcessPendingDelivery::class)->handle(DeliveryId::fromString($replayId));
        $this->assertDatabaseHas('deliveries', ['public_id' => $replayId, 'status' => 'retry_scheduled']);
        $this->assertDatabaseHas('delivery_outbox_messages', ['dedupe_key' => "delivery:{$replayId}:attempt:2", 'status' => 'pending']);
        $clock->set(new DateTimeImmutable('2026-09-03T12:00:10+00:00'));
        app(ProcessPendingDelivery::class)->handle(DeliveryId::fromString($replayId));

        self::assertCount(2, $transport->requests);
        self::assertSame($keyTwo['key_id'], $transport->requests[0]->headers['X-EventRelay-Signing-Key-Id']);
        self::assertSame($keyTwo['key_id'], $transport->requests[1]->headers['X-EventRelay-Signing-Key-Id']);
        self::assertNotSame($keyOne['key_id'], $keyTwo['key_id']);
        self::assertNotSame($keyTwo['key_id'], $keyThree['key_id']);
        self::assertSame('1', $transport->requests[0]->headers['X-EventRelay-Attempt']);
        self::assertSame('2', $transport->requests[1]->headers['X-EventRelay-Attempt']);
        $this->assertDatabaseHas('deliveries', ['public_id' => $sourceId, 'status' => 'failed']);
        $this->assertDatabaseHas('deliveries', ['public_id' => $replayId, 'status' => 'succeeded']);
    }

    /** @return array{string,string,string} */
    private function failedSource(?string $endpointId = null): array
    {
        [$eventId, $endpointId, $deliveryId] = $this->source('failed', $endpointId);

        return [$eventId, $endpointId, $deliveryId];
    }

    /** @return array{string,string,string} */
    private function source(string $status, ?string $existingEndpointId = null): array
    {
        $endpointId = $existingEndpointId ?? (string) $this->postJson('/api/endpoints', [
            'name' => 'Replay endpoint '.uniqid(), 'url' => 'https://original.example/webhook',
        ])->assertCreated()->json('data.id');
        $eventId = (string) $this->postJson('/api/events', ['type' => 'order.paid', 'payload' => (object) []])->assertCreated()->json('data.id');
        $deliveryId = app(CreateDelivery::class)->handle($eventId, $endpointId)->id;
        DB::table('deliveries')->where('public_id', $deliveryId)->update(['status' => $status]);

        return [$eventId, $endpointId, $deliveryId];
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Application\Clock\Clock;
use App\Application\DeadLetter\DeadLetterConsistencyViolation;
use App\Application\DeadLetter\DeadLetterFilter;
use App\Application\DeadLetter\ListDeadLetters;
use App\Application\Delivery\CreateDelivery;
use App\Application\Delivery\ProcessPendingDelivery;
use App\Application\Delivery\RecoverStaleDelivery;
use App\Application\Delivery\WebhookRequest;
use App\Application\Delivery\WebhookResponse;
use App\Application\Delivery\WebhookTarget;
use App\Application\Delivery\WebhookTargetResolver;
use App\Application\Delivery\WebhookTransport;
use App\Domain\Delivery\DeliveryId;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FrozenClock;
use Tests\TestCase;

final class DeadLetterApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_one_failed_delivery_row_from_its_highest_attempt_without_n_plus_one_queries(): void
    {
        [, , $deliveryId] = $this->createFailedDelivery(
            eventType: 'order.failed',
            responseStatus: 400,
            attemptCount: 3,
            failedAt: new DateTimeImmutable('2026-09-05T12:00:00+00:00'),
        );

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->getJson('/api/dead-letters')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $response
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.delivery_id', $deliveryId)
            ->assertJsonPath('data.0.event_type', 'order.failed')
            ->assertJsonPath('data.0.attempt_count', 3)
            ->assertJsonPath('data.0.last_attempt_number', 3)
            ->assertJsonPath('data.0.failure_type', 'http_status')
            ->assertJsonPath('data.0.response_status', 400)
            ->assertJsonPath('meta.next_cursor', null);

        self::assertCount(1, $queries);
        self::assertStringContainsString('delivery_attempts', strtolower($queries[0]['query']));
    }

    public function test_it_filters_failed_deliveries_with_and_semantics_and_rejects_invalid_filters(): void
    {
        [$eventOne, $endpointOne, $first] = $this->createFailedDelivery('order.paid', 'http_status', 400);
        [, $endpointTwo] = $this->createFailedDelivery('invoice.paid', 'timeout');
        $this->createFailedDelivery('order.paid', 'http_status', 500);

        $this->getJson('/api/dead-letters?'.http_build_query([
            'endpoint_id' => $endpointOne,
            'event_type' => 'order.paid',
            'failure_type' => 'http_status',
            'response_status' => '400',
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.delivery_id', $first)
            ->assertJsonPath('data.0.event_id', $eventOne)
            ->assertJsonPath('data.0.endpoint_id', $endpointOne);

        foreach ([
            'endpoint_id=not-a-uuid',
            'event_type=UPPERCASE',
            'failure_type=unknown',
            'response_status=99',
            'response_status=600',
            'response_status=abc',
            'limit=0',
            'limit=101',
            'limit=abc',
            'unsupported=value',
        ] as $query) {
            $this->getJson("/api/dead-letters?{$query}")
                ->assertUnprocessable()
                ->assertJsonPath('code', 'invalid_dead_letter_filter');
        }

        $this->getJson('/api/dead-letters?endpoint_id[]='.$endpointTwo)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_dead_letter_filter');
    }

    public function test_keyset_pagination_is_stable_for_same_failed_at_and_authenticates_its_cursor(): void
    {
        $failedAt = new DateTimeImmutable('2026-09-05T12:00:00+00:00');
        $deliveryIds = [];
        for ($index = 0; $index < 5; $index++) {
            [, , $deliveryIds[]] = $this->createFailedDelivery('order.paid', 'http_status', 400, 1, $failedAt);
        }
        rsort($deliveryIds, SORT_STRING);

        $first = $this->getJson('/api/dead-letters?limit=2')->assertOk();
        $cursor = (string) $first->json('meta.next_cursor');
        self::assertNotSame('', $cursor);
        foreach ($deliveryIds as $deliveryId) {
            self::assertStringNotContainsString($deliveryId, $cursor);
        }

        $second = $this->getJson('/api/dead-letters?'.http_build_query(['limit' => 2, 'cursor' => $cursor]))->assertOk();
        $third = $this->getJson('/api/dead-letters?'.http_build_query([
            'limit' => 2,
            'cursor' => $second->json('meta.next_cursor'),
        ]))->assertOk();

        $actual = array_merge(
            array_column($first->json('data'), 'delivery_id'),
            array_column($second->json('data'), 'delivery_id'),
            array_column($third->json('data'), 'delivery_id'),
        );
        self::assertSame($deliveryIds, $actual);
        self::assertSame(count($actual), count(array_unique($actual)));

        $tampered = ($cursor[0] === 'A' ? 'B' : 'A').substr($cursor, 1);
        $this->getJson('/api/dead-letters?'.http_build_query(['cursor' => $tampered]))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_dead_letter_cursor');

        $endpoint = (string) $first->json('data.0.endpoint_id');
        $this->getJson('/api/dead-letters?'.http_build_query(['cursor' => $cursor, 'endpoint_id' => $endpoint]))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_dead_letter_cursor');
    }

    public function test_history_remains_queryable_after_endpoint_configuration_changes_and_soft_delete_without_sensitive_leaks(): void
    {
        [$eventId, $endpointId, $deliveryId] = $this->createFailedDelivery('order.paid', 'http_status', 400);
        DB::table('delivery_attempts')->where('delivery_id', $this->internalDeliveryId($deliveryId))->update([
            'failure_message' => 'whsec_private-fixture raw-idempotency-key target_url=private.example',
        ]);
        $this->patchJson("/api/endpoints/{$endpointId}", ['url' => 'https://changed.example/webhook'])->assertOk();
        $this->deleteJson("/api/endpoints/{$endpointId}")->assertNoContent();

        $response = $this->getJson('/api/dead-letters')->assertOk();
        $response
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.delivery_id', $deliveryId)
            ->assertJsonPath('data.0.event_id', $eventId)
            ->assertJsonPath('data.0.endpoint_id', $endpointId)
            ->assertJsonMissingPath('data.0.failure_message')
            ->assertJsonMissingPath('data.0.target_url')
            ->assertJsonMissingPath('data.0.signing_secret_id')
            ->assertJsonMissingPath('data.0.creation_key');
        $content = $response->getContent();
        self::assertIsString($content);
        self::assertStringNotContainsString('whsec_private-fixture', $content);
        self::assertStringNotContainsString('raw-idempotency-key', $content);
        self::assertStringNotContainsString('private.example', $content);
    }

    public function test_failed_replay_is_listed_while_a_successful_replay_keeps_its_failed_source_in_dead_letters(): void
    {
        [, $endpointId, $sourceId] = $this->createFailedDelivery('order.paid', 'http_status', 400);
        $successfulReplay = (string) $this->postJson("/api/deliveries/{$sourceId}/replay", [], ['Idempotency-Key' => 'dead-letter-success'])
            ->assertCreated()
            ->json('data.id');
        $this->bindSuccessfulTransport();
        app(ProcessPendingDelivery::class)->handle(DeliveryId::fromString($successfulReplay));

        $failedReplay = (string) $this->postJson("/api/deliveries/{$sourceId}/replay", [], ['Idempotency-Key' => 'dead-letter-failed'])
            ->assertCreated()
            ->json('data.id');
        $this->markFailed($failedReplay, 'network_error', null, 1, new DateTimeImmutable('2026-09-05T12:10:00+00:00'));

        $response = $this->getJson('/api/dead-letters')->assertOk();
        $items = $this->responseData($response->json('data'));
        $listed = array_column($items, 'delivery_id');
        self::assertContains($sourceId, $listed);
        self::assertContains($failedReplay, $listed);
        self::assertNotContains($successfulReplay, $listed);
        $failedReplayItem = $this->itemForDelivery($items, $failedReplay);
        self::assertSame($sourceId, $failedReplayItem['replay_of_delivery_id']);
        self::assertSame($endpointId, $failedReplayItem['endpoint_id']);
    }

    public function test_a_failed_delivery_without_a_terminal_latest_attempt_fails_closed(): void
    {
        [, , $deliveryId] = $this->createDelivery('order.paid');
        DB::table('deliveries')->where('public_id', $deliveryId)->update(['status' => 'failed']);

        $this->expectException(DeadLetterConsistencyViolation::class);

        app(ListDeadLetters::class)->handle(DeadLetterFilter::fromQuery([]), null);
    }

    public function test_a_failed_delivery_with_a_started_latest_attempt_fails_closed(): void
    {
        [, , $deliveryId] = $this->createFailedDelivery();
        DB::table('delivery_attempts')->where('delivery_id', $this->internalDeliveryId($deliveryId))->update([
            'status' => 'started',
            'failure_type' => null,
            'finished_at' => null,
        ]);

        $this->expectException(DeadLetterConsistencyViolation::class);

        app(ListDeadLetters::class)->handle(DeadLetterFilter::fromQuery([]), null);
    }

    public function test_real_http_400_and_exhausted_500_attempts_are_projected_from_the_final_attempt(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-09-05T12:00:00+00:00'));
        $this->app->instance(Clock::class, $clock);
        $this->app->instance(WebhookTargetResolver::class, new class implements WebhookTargetResolver
        {
            public function resolve(string $url): WebhookTarget
            {
                return new WebhookTarget($url, 'receiver.example', 443, '1.1.1.1');
            }
        });

        [, , $http400] = $this->createDelivery('http.400');
        $this->app->instance(WebhookTransport::class, new class implements WebhookTransport
        {
            public function send(WebhookTarget $target, WebhookRequest $request): WebhookResponse
            {
                return new WebhookResponse(400, 1);
            }
        });
        app(ProcessPendingDelivery::class)->handle(DeliveryId::fromString($http400));

        [, , $http500] = $this->createDelivery('http.500');
        $this->app->instance(WebhookTransport::class, new class implements WebhookTransport
        {
            public function send(WebhookTarget $target, WebhookRequest $request): WebhookResponse
            {
                return new WebhookResponse(500, 1);
            }
        });
        app(ProcessPendingDelivery::class)->handle(DeliveryId::fromString($http500));
        $clock->set(new DateTimeImmutable('2026-09-05T12:00:10+00:00'));
        app(ProcessPendingDelivery::class)->handle(DeliveryId::fromString($http500));
        $clock->set(new DateTimeImmutable('2026-09-05T12:01:10+00:00'));
        app(ProcessPendingDelivery::class)->handle(DeliveryId::fromString($http500));

        $items = $this->responseData($this->getJson('/api/dead-letters')->assertOk()->json('data'));
        self::assertSame(1, $this->itemForDelivery($items, $http400)['last_attempt_number']);
        self::assertSame(3, $this->itemForDelivery($items, $http500)['last_attempt_number']);
        self::assertSame(500, $this->itemForDelivery($items, $http500)['response_status']);
    }

    public function test_timeout_network_unsafe_and_stale_history_use_existing_failure_metadata_without_new_dead_letter_state(): void
    {
        [, , $timeout] = $this->createFailedDelivery('timeout.event', 'timeout', null);
        [, , $network] = $this->createFailedDelivery('network.event', 'network_error', null);
        [, , $unsafe] = $this->createFailedDelivery('unsafe.event', 'unsafe_target', null);
        $staleDelivery = $this->createStaleThenPermanentFailure();

        $items = $this->responseData($this->getJson('/api/dead-letters')->assertOk()->json('data'));
        self::assertSame('timeout', $this->itemForDelivery($items, $timeout)['failure_type']);
        self::assertSame('network_error', $this->itemForDelivery($items, $network)['failure_type']);
        self::assertSame('unsafe_target', $this->itemForDelivery($items, $unsafe)['failure_type']);
        self::assertSame('http_status', $this->itemForDelivery($items, $staleDelivery)['failure_type']);
        self::assertSame(2, $this->itemForDelivery($items, $staleDelivery)['last_attempt_number']);
        $this->assertDatabaseHas('delivery_attempts', [
            'delivery_id' => $this->internalDeliveryId($staleDelivery),
            'attempt_number' => 1,
            'status' => 'abandoned',
            'failure_type' => 'stale_processing',
        ]);
    }

    /** @return array{string,string,string} */
    private function createFailedDelivery(
        string $eventType = 'order.paid',
        string $failureType = 'http_status',
        ?int $responseStatus = 400,
        int $attemptCount = 1,
        ?DateTimeImmutable $failedAt = null,
    ): array {
        [$eventId, $endpointId, $deliveryId] = $this->createDelivery($eventType);
        $this->markFailed($deliveryId, $failureType, $responseStatus, $attemptCount, $failedAt ?? new DateTimeImmutable('2026-09-05T12:00:00+00:00'));

        return [$eventId, $endpointId, $deliveryId];
    }

    /** @return array{string,string,string} */
    private function createDelivery(string $eventType): array
    {
        $endpointId = (string) $this->postJson('/api/endpoints', [
            'name' => 'Dead-letter endpoint '.Str::uuid(),
            'url' => 'https://receiver.example/dead-letters',
        ])->assertCreated()->json('data.id');
        $eventId = (string) $this->postJson('/api/events', [
            'type' => $eventType,
            'payload' => (object) [],
        ])->assertCreated()->json('data.id');
        $deliveryId = app(CreateDelivery::class)->handle($eventId, $endpointId)->id;

        return [$eventId, $endpointId, $deliveryId];
    }

    private function markFailed(
        string $deliveryId,
        string $failureType,
        ?int $responseStatus,
        int $attemptCount,
        DateTimeImmutable $failedAt,
    ): void {
        $internalDeliveryId = $this->internalDeliveryId($deliveryId);
        DB::table('deliveries')->where('id', $internalDeliveryId)->update([
            'status' => 'failed',
            'updated_at' => $failedAt->format('Y-m-d H:i:s'),
        ]);
        for ($number = 1; $number <= $attemptCount; $number++) {
            DB::table('delivery_attempts')->insert([
                'public_id' => (string) Str::uuid(),
                'delivery_id' => $internalDeliveryId,
                'attempt_number' => $number,
                'status' => 'failed',
                'response_status' => $responseStatus,
                'failure_type' => $failureType,
                'failure_message' => 'Failure fixture.',
                'duration_ms' => 1,
                'started_at' => $failedAt->format('Y-m-d H:i:s'),
                'finished_at' => $failedAt->format('Y-m-d H:i:s'),
            ]);
        }
    }

    private function internalDeliveryId(string $deliveryId): int
    {
        /** @var int $internalId */
        $internalId = DB::table('deliveries')->where('public_id', $deliveryId)->value('id');

        return $internalId;
    }

    private function bindSuccessfulTransport(): void
    {
        $this->app->instance(WebhookTargetResolver::class, new class implements WebhookTargetResolver
        {
            public function resolve(string $url): WebhookTarget
            {
                return new WebhookTarget($url, 'receiver.example', 443, '1.1.1.1');
            }
        });
        $this->app->instance(WebhookTransport::class, new class implements WebhookTransport
        {
            public function send(WebhookTarget $target, WebhookRequest $request): WebhookResponse
            {
                return new WebhookResponse(200, 1);
            }
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function responseData(mixed $data): array
    {
        if (! is_array($data)) {
            self::fail('Dead-letter response data must be an array.');
        }

        foreach ($data as $item) {
            if (! is_array($item)) {
                self::fail('Dead-letter response entries must be objects.');
            }
        }

        return array_values($data);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function itemForDelivery(array $items, string $deliveryId): array
    {
        foreach ($items as $item) {
            if (($item['delivery_id'] ?? null) === $deliveryId) {
                return $item;
            }
        }

        self::fail("Dead-letter item {$deliveryId} was not found.");
    }

    private function createStaleThenPermanentFailure(): string
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-09-05T13:00:00+00:00'));
        $this->app->instance(Clock::class, $clock);
        $this->app->instance(WebhookTargetResolver::class, new class implements WebhookTargetResolver
        {
            public function resolve(string $url): WebhookTarget
            {
                return new WebhookTarget($url, 'receiver.example', 443, '1.1.1.1');
            }
        });
        [, , $deliveryId] = $this->createDelivery('stale.event');
        $this->app->instance(WebhookTransport::class, new class implements WebhookTransport
        {
            public function send(WebhookTarget $target, WebhookRequest $request): WebhookResponse
            {
                throw new \LogicException('Unknown worker failure fixture.');
            }
        });

        try {
            app(ProcessPendingDelivery::class)->handle(DeliveryId::fromString($deliveryId));
            self::fail('The fixture must leave a started attempt for stale recovery.');
        } catch (\LogicException $exception) {
            self::assertSame('Unknown worker failure fixture.', $exception->getMessage());
        }

        $clock->set(new DateTimeImmutable('2026-09-05T13:01:00+00:00'));
        self::assertNotNull(app(RecoverStaleDelivery::class)->handle(DeliveryId::fromString($deliveryId)));
        $clock->set(new DateTimeImmutable('2026-09-05T13:01:10+00:00'));
        $this->app->instance(WebhookTransport::class, new class implements WebhookTransport
        {
            public function send(WebhookTarget $target, WebhookRequest $request): WebhookResponse
            {
                return new WebhookResponse(400, 1);
            }
        });
        app(ProcessPendingDelivery::class)->handle(DeliveryId::fromString($deliveryId));

        return $deliveryId;
    }
}

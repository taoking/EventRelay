<?php

declare(strict_types=1);

namespace Tests\Feature\Outbox;

use App\Application\Clock\Clock;
use App\Application\Delivery\CreateDelivery;
use App\Application\Delivery\DeliveryExecutionIntent;
use App\Application\Delivery\DeliveryExecutionRepository;
use App\Application\Delivery\DeliveryOutboxPublisherRepository;
use App\Application\Delivery\DeliveryOutboxWriter;
use App\Application\Delivery\DeliveryQueue;
use App\Application\Delivery\DeliveryQueueUnavailable;
use App\Application\Delivery\ProcessPendingDelivery;
use App\Application\Delivery\PublishDeliveryOutbox;
use App\Application\Delivery\RecoverStaleDelivery;
use App\Application\Delivery\WebhookRequest;
use App\Application\Delivery\WebhookResponse;
use App\Application\Delivery\WebhookTarget;
use App\Application\Delivery\WebhookTargetResolver;
use App\Application\Delivery\WebhookTransport;
use App\Domain\Delivery\DeliveryId;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\FrozenClock;
use Tests\TestCase;

final class DeliveryOutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_publisher_marks_an_initial_outbox_message_published_only_after_queue_accepts_the_delivery_uuid(): void
    {
        $deliveryId = $this->createEventWithOneMatchingEndpoint();
        $published = [];
        $this->app->instance(DeliveryQueue::class, new class($published) implements DeliveryQueue
        {
            /** @param list<string> $published */
            public function __construct(array &$published)
            {
                $this->published = &$published;
            }

            /** @var list<string> */
            private array $published;

            public function enqueue(DeliveryId $deliveryId): void
            {
                $this->published[] = $deliveryId->toString();
            }

            public function schedule(DeliveryId $deliveryId, DateTimeImmutable $availableAt): void
            {
                throw new RuntimeException('Initial delivery must not use delayed scheduling.');
            }
        });

        $result = app(PublishDeliveryOutbox::class)->handle(100);

        self::assertSame(1, $result->published);
        self::assertSame(0, $result->failed);
        self::assertSame(0, $result->lostLease);
        self::assertSame([$deliveryId], $published);
        $this->assertDatabaseHas('delivery_outbox_messages', [
            'dedupe_key' => "delivery:{$deliveryId}:attempt:1",
            'message_type' => 'delivery.process',
            'status' => 'published',
        ]);
        self::assertNotNull(DB::table('delivery_outbox_messages')->value('published_at'));
    }

    public function test_outbox_persists_only_delivery_execution_metadata_and_deduplicates_the_same_attempt_intent(): void
    {
        $deliveryId = $this->createEventWithOneMatchingEndpoint();
        $intent = new DeliveryExecutionIntent(
            DeliveryId::fromString($deliveryId),
            2,
            new DateTimeImmutable('2026-09-02T12:00:10+00:00'),
        );

        app(DeliveryOutboxWriter::class)->schedule($intent, new DateTimeImmutable('2026-09-02T12:00:00+00:00'));
        app(DeliveryOutboxWriter::class)->schedule($intent, new DateTimeImmutable('2026-09-02T12:00:01+00:00'));

        self::assertSame(2, DB::table('delivery_outbox_messages')->count());
        $row = (array) DB::table('delivery_outbox_messages')
            ->where('dedupe_key', "delivery:{$deliveryId}:attempt:2")
            ->first();
        self::assertSame('delivery.process', $row['message_type']);
        self::assertArrayNotHasKey('payload', $row);
        self::assertArrayNotHasKey('target_url', $row);
        self::assertArrayNotHasKey('secret', $row);
        self::assertArrayNotHasKey('encrypted_secret', $row);
        self::assertArrayNotHasKey('signature', $row);
        self::assertStringNotContainsString('outbox-1001', json_encode($row, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('receiver.example', json_encode($row, JSON_THROW_ON_ERROR));
    }

    public function test_known_redis_failure_releases_outbox_message_without_losing_the_durable_intent(): void
    {
        $deliveryId = $this->createEventWithOneMatchingEndpoint();
        $this->app->instance(DeliveryQueue::class, new class implements DeliveryQueue
        {
            public function enqueue(DeliveryId $deliveryId): void
            {
                throw new DeliveryQueueUnavailable($deliveryId, new RuntimeException('Redis unavailable.'));
            }

            public function schedule(DeliveryId $deliveryId, DateTimeImmutable $availableAt): void
            {
                throw new DeliveryQueueUnavailable($deliveryId, new RuntimeException('Redis unavailable.'));
            }
        });

        $result = app(PublishDeliveryOutbox::class)->handle(100);

        self::assertSame(0, $result->published);
        self::assertSame(1, $result->failed);
        $this->assertDatabaseHas('delivery_outbox_messages', [
            'dedupe_key' => "delivery:{$deliveryId}:attempt:1",
            'status' => 'pending',
            'last_error_code' => 'redis_unavailable',
            'publication_attempts' => 1,
        ]);
        self::assertNull(DB::table('delivery_outbox_messages')->value('published_at'));
    }

    public function test_unknown_queue_error_propagates_and_does_not_mark_the_outbox_message_published(): void
    {
        $deliveryId = $this->createEventWithOneMatchingEndpoint();
        $this->app->instance(DeliveryQueue::class, new class implements DeliveryQueue
        {
            public function enqueue(DeliveryId $deliveryId): void
            {
                throw new RuntimeException('Unexpected queue programming error.');
            }

            public function schedule(DeliveryId $deliveryId, DateTimeImmutable $availableAt): void
            {
                throw new RuntimeException('Unexpected queue programming error.');
            }
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected queue programming error.');

        try {
            app(PublishDeliveryOutbox::class)->handle(100);
        } finally {
            $this->assertDatabaseHas('delivery_outbox_messages', [
                'dedupe_key' => "delivery:{$deliveryId}:attempt:1",
                'status' => 'publishing',
                'publication_attempts' => 1,
            ]);
            self::assertNull(DB::table('delivery_outbox_messages')->value('published_at'));
        }
    }

    public function test_a_claimed_message_is_republished_after_lease_expiry_when_a_publisher_crashes_after_queue_acceptance_before_marking_published(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-09-02T12:00:00+00:00'));
        $this->app->instance(Clock::class, $clock);
        $deliveryId = $this->createEventWithOneMatchingEndpoint();
        $outbox = app(DeliveryOutboxPublisherRepository::class);
        $firstClaim = $outbox->claim(1, $clock->now(), $clock->now()->add(new DateInterval('PT60S')));
        self::assertCount(1, $firstClaim);

        $published = [];
        $this->app->instance(DeliveryQueue::class, new class($published) implements DeliveryQueue
        {
            /** @param list<string> $published */
            public function __construct(array &$published)
            {
                $this->published = &$published;
            }

            /** @var list<string> */
            private array $published;

            public function enqueue(DeliveryId $deliveryId): void
            {
                $this->published[] = $deliveryId->toString();
            }

            public function schedule(DeliveryId $deliveryId, DateTimeImmutable $availableAt): void
            {
                throw new RuntimeException('Initial delivery must not use delayed scheduling.');
            }
        });

        // 模拟 Redis 已接受 Job 后 Publisher 进程在 markPublished 前崩溃。
        app(DeliveryQueue::class)->enqueue($firstClaim[0]->intent->deliveryId);
        $clock->set(new DateTimeImmutable('2026-09-02T12:01:00+00:00'));

        $result = app(PublishDeliveryOutbox::class)->handle(100);

        self::assertSame([$deliveryId, $deliveryId], $published);
        self::assertSame(1, $result->published);
        $this->assertDatabaseHas('delivery_outbox_messages', [
            'dedupe_key' => "delivery:{$deliveryId}:attempt:1",
            'status' => 'published',
            'publication_attempts' => 2,
        ]);
    }

    public function test_retry_finalize_and_next_execution_intent_roll_back_together_when_outbox_persistence_fails(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-09-02T12:00:00+00:00'));
        $this->app->instance(Clock::class, $clock);
        $this->app->instance(DeliveryOutboxWriter::class, new class implements DeliveryOutboxWriter
        {
            public function schedule(DeliveryExecutionIntent $intent, DateTimeImmutable $now): void
            {
                throw new RuntimeException('Forced outbox failure.');
            }
        });
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
                return new WebhookResponse(500, 1);
            }
        });

        $deliveryId = $this->createStandaloneDelivery();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Forced outbox failure.');

        try {
            app(ProcessPendingDelivery::class)->handle(DeliveryId::fromString($deliveryId));
        } finally {
            $this->assertDatabaseHas('deliveries', [
                'public_id' => $deliveryId,
                'status' => 'processing',
                'next_attempt_at' => null,
            ]);
            $this->assertDatabaseHas('delivery_attempts', [
                'attempt_number' => 1,
                'status' => 'started',
            ]);
            $this->assertDatabaseCount('delivery_outbox_messages', 0);
        }
    }

    public function test_stale_recovery_state_and_next_execution_intent_roll_back_together_when_outbox_persistence_fails(): void
    {
        $startedAt = new DateTimeImmutable('2026-09-02T12:00:00+00:00');
        $clock = new FrozenClock($startedAt);
        $this->app->instance(Clock::class, $clock);
        $deliveryId = $this->createStandaloneDelivery();
        $claimed = app(DeliveryExecutionRepository::class)->claim(DeliveryId::fromString($deliveryId), $startedAt);
        self::assertNotNull($claimed);

        $this->app->instance(DeliveryOutboxWriter::class, new class implements DeliveryOutboxWriter
        {
            public function schedule(DeliveryExecutionIntent $intent, DateTimeImmutable $now): void
            {
                throw new RuntimeException('Forced outbox failure.');
            }
        });
        $clock->set(new DateTimeImmutable('2026-09-02T12:01:00+00:00'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Forced outbox failure.');

        try {
            app(RecoverStaleDelivery::class)->handle(DeliveryId::fromString($deliveryId));
        } finally {
            $this->assertDatabaseHas('deliveries', [
                'public_id' => $deliveryId,
                'status' => 'processing',
                'next_attempt_at' => null,
            ]);
            $this->assertDatabaseHas('delivery_attempts', [
                'public_id' => $claimed->attempt->id()->toString(),
                'status' => 'started',
            ]);
            $this->assertDatabaseCount('delivery_outbox_messages', 0);
        }
    }

    private function createEventWithOneMatchingEndpoint(): string
    {
        $endpointId = (string) $this->postJson('/api/endpoints', [
            'name' => 'Outbox endpoint',
            'url' => 'https://receiver.example/outbox',
        ])->assertCreated()->json('data.id');
        $this->putJson("/api/endpoints/{$endpointId}/subscriptions", ['types' => ['order.paid']])->assertOk();
        $eventId = (string) $this->postJson('/api/events', [
            'type' => 'order.paid',
            'payload' => (object) ['order_id' => 'outbox-1001'],
        ])->assertCreated()->json('data.id');

        /** @var string $deliveryId */
        $deliveryId = DB::table('deliveries')
            ->join('events', 'deliveries.event_id', '=', 'events.id')
            ->where('events.public_id', $eventId)
            ->value('deliveries.public_id');

        return $deliveryId;
    }

    private function createStandaloneDelivery(): string
    {
        $endpointId = (string) $this->postJson('/api/endpoints', [
            'name' => 'Outbox retry endpoint',
            'url' => 'https://receiver.example/outbox-retry',
        ])->assertCreated()->json('data.id');
        $eventId = (string) $this->postJson('/api/events', [
            'type' => 'order.paid',
            'payload' => (object) [],
        ])->assertCreated()->json('data.id');

        return app(CreateDelivery::class)->handle($eventId, $endpointId)->id;
    }
}

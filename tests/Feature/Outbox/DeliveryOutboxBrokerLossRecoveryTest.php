<?php

declare(strict_types=1);

namespace Tests\Feature\Outbox;

use App\Application\Clock\Clock;
use App\Application\Delivery\EnqueueDueRetries;
use App\Application\Delivery\EnqueuePendingDeliveries;
use App\Application\Delivery\ProcessPendingDelivery;
use App\Application\Delivery\PublishDeliveryOutbox;
use App\Application\Delivery\WebhookRequest;
use App\Application\Delivery\WebhookResponse;
use App\Application\Delivery\WebhookTarget;
use App\Application\Delivery\WebhookTargetResolver;
use App\Application\Delivery\WebhookTransport;
use App\Domain\Delivery\DeliveryId;
use App\Infrastructure\Queue\ProcessDeliveryJob;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\Support\FrozenClock;
use Tests\TestCase;

final class DeliveryOutboxBrokerLossRecoveryTest extends TestCase
{
    use DatabaseMigrations;

    public function test_pending_recovery_rearms_a_published_initial_intent_after_broker_job_loss(): void
    {
        $this->requireMySqlAndRedis();
        $this->clearDeliveriesQueue();

        $endpointId = $this->createEndpoint('Initial broker-loss endpoint', 'http://127.0.0.1/broker-loss-initial');
        $this->putJson("/api/endpoints/{$endpointId}/subscriptions", ['types' => ['order.paid']])->assertOk();
        $eventId = (string) $this->postJson('/api/events', [
            'type' => 'order.paid',
            'payload' => (object) ['source' => 'broker-loss-initial'],
        ])->assertCreated()->json('data.id');
        $deliveryId = $this->deliveryIdForEvent($eventId);

        self::assertSame(1, app(PublishDeliveryOutbox::class)->handle(100)->published);
        self::assertSame(1, $this->queueLength());

        // 受控 broker-state-loss fixture：Outbox 已标 published，但 Worker 尚未取走 Job。
        $this->clearDeliveriesQueue();
        self::assertSame(0, $this->queueLength());
        $this->assertDatabaseHas('deliveries', ['public_id' => $deliveryId, 'status' => 'pending']);
        $this->assertDatabaseCount('delivery_attempts', 0);
        $this->assertDatabaseHas('delivery_outbox_messages', [
            'dedupe_key' => "delivery:{$deliveryId}:attempt:1",
            'status' => 'published',
        ]);

        self::assertSame(1, app(EnqueuePendingDeliveries::class)->handle(100)->ensured);
        $this->assertDatabaseHas('delivery_outbox_messages', [
            'dedupe_key' => "delivery:{$deliveryId}:attempt:1",
            'status' => 'pending',
            'last_error_code' => 'broker_job_lost',
        ]);
        self::assertNull(DB::table('delivery_outbox_messages')->where('dedupe_key', "delivery:{$deliveryId}:attempt:1")->value('published_at'));

        self::assertSame(1, app(PublishDeliveryOutbox::class)->handle(100)->published);
        self::assertSame(1, $this->queueLength());
        self::assertSame(0, Artisan::call('queue:work', [
            'connection' => 'redis',
            '--queue' => 'deliveries',
            '--once' => true,
            '--tries' => 1,
        ]));
        $this->assertDatabaseHas('deliveries', ['public_id' => $deliveryId, 'status' => 'failed']);
        $this->assertDatabaseHas('delivery_attempts', [
            'delivery_id' => DB::table('deliveries')->where('public_id', $deliveryId)->value('id'),
            'attempt_number' => 1,
            'failure_type' => 'unsafe_target',
        ]);
    }

    public function test_due_retry_recovery_rearms_a_published_retry_intent_after_broker_job_loss(): void
    {
        $this->requireMySqlAndRedis();
        $this->clearDeliveriesQueue();
        $clock = new FrozenClock(new DateTimeImmutable('2026-09-04T12:00:00+00:00'));
        $this->app->instance(Clock::class, $clock);
        $this->app->instance(WebhookTargetResolver::class, new class implements WebhookTargetResolver
        {
            public function resolve(string $targetUrl): WebhookTarget
            {
                return new WebhookTarget($targetUrl, 'receiver.example', 443, '1.1.1.1');
            }
        });
        $this->app->instance(WebhookTransport::class, new class implements WebhookTransport
        {
            private int $calls = 0;

            public function send(WebhookTarget $target, WebhookRequest $request): WebhookResponse
            {
                $this->calls++;

                return new WebhookResponse($this->calls === 1 ? 500 : 200, 1);
            }
        });

        $endpointId = $this->createEndpoint('Retry broker-loss endpoint');
        $this->putJson("/api/endpoints/{$endpointId}/subscriptions", ['types' => ['order.paid']])->assertOk();
        $eventId = (string) $this->postJson('/api/events', [
            'type' => 'order.paid',
            'payload' => (object) ['source' => 'broker-loss-retry'],
        ])->assertCreated()->json('data.id');
        $deliveryId = $this->deliveryIdForEvent($eventId);

        // 先确认 initial intent 的 publication，再由真实 Application 产生 retry attempt:2 intent。
        self::assertSame(1, app(PublishDeliveryOutbox::class)->handle(100)->published);
        $this->clearDeliveriesQueue();
        app(ProcessPendingDelivery::class)->handle(DeliveryId::fromString($deliveryId));

        $nextAttemptAt = new DateTimeImmutable('2026-09-04T12:00:10+00:00');
        $this->assertDatabaseHas('deliveries', [
            'public_id' => $deliveryId,
            'status' => 'retry_scheduled',
            'next_attempt_at' => $nextAttemptAt,
        ]);
        $this->assertDatabaseHas('delivery_attempts', [
            'delivery_id' => DB::table('deliveries')->where('public_id', $deliveryId)->value('id'),
            'attempt_number' => 1,
            'status' => 'failed',
            'response_status' => 500,
        ]);

        // Outbox 是唯一的 due gate：未来 retry intent 不能提前交给 Redis delayed queue。
        self::assertSame(0, app(PublishDeliveryOutbox::class)->handle(100)->published);
        self::assertSame(0, $this->queueLength());

        $clock->set($nextAttemptAt);
        self::assertSame(1, app(PublishDeliveryOutbox::class)->handle(100)->published);
        self::assertSame(1, $this->queueLength());

        // 删除尚未消费的即时 Job，模拟 Redis broker state loss。
        $this->clearDeliveriesQueue();
        self::assertSame(0, $this->queueLength());
        $this->assertDatabaseHas('delivery_outbox_messages', [
            'dedupe_key' => "delivery:{$deliveryId}:attempt:2",
            'status' => 'published',
        ]);

        self::assertSame(1, app(EnqueueDueRetries::class)->handle(100)->ensured);
        $this->assertDatabaseHas('delivery_outbox_messages', [
            'dedupe_key' => "delivery:{$deliveryId}:attempt:2",
            'status' => 'pending',
            'last_error_code' => 'broker_job_lost',
        ]);

        self::assertSame(1, app(PublishDeliveryOutbox::class)->handle(100)->published);
        self::assertSame(1, $this->queueLength());

        (new ProcessDeliveryJob($deliveryId))->handle(app(ProcessPendingDelivery::class));
        $this->assertDatabaseHas('deliveries', ['public_id' => $deliveryId, 'status' => 'succeeded']);
        $this->assertDatabaseHas('delivery_attempts', [
            'delivery_id' => DB::table('deliveries')->where('public_id', $deliveryId)->value('id'),
            'attempt_number' => 2,
            'status' => 'succeeded',
            'response_status' => 200,
        ]);
        self::assertSame(2, DB::table('delivery_attempts')->count());
    }

    private function requireMySqlAndRedis(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('This regression test requires MySQL and Redis.');
        }

        Redis::connection()->ping();
    }

    private function clearDeliveriesQueue(): void
    {
        Redis::connection()->del('queues:deliveries', 'queues:deliveries:delayed', 'queues:deliveries:reserved');
    }

    private function queueLength(): int
    {
        return Redis::connection()->lLen('queues:deliveries');
    }

    private function createEndpoint(string $name, string $url = 'https://receiver.example/broker-loss'): string
    {
        return (string) $this->postJson('/api/endpoints', [
            'name' => $name,
            'url' => $url,
        ])->assertCreated()->json('data.id');
    }

    private function deliveryIdForEvent(string $eventId): string
    {
        /** @var string $deliveryId */
        $deliveryId = DB::table('deliveries')
            ->join('events', 'deliveries.event_id', '=', 'events.id')
            ->where('events.public_id', $eventId)
            ->value('deliveries.public_id');

        return $deliveryId;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Application\Clock\Clock;
use App\Application\Delivery\CreateDelivery;
use App\Application\Delivery\DeliveryQueue;
use App\Application\Delivery\DeliveryQueueUnavailable;
use App\Application\Delivery\EnqueuePendingDeliveries;
use App\Application\Delivery\WebhookRequest;
use App\Application\Delivery\WebhookResponse;
use App\Application\Delivery\WebhookTarget;
use App\Application\Delivery\WebhookTargetResolver;
use App\Application\Delivery\WebhookTransport;
use App\Domain\Delivery\DeliveryId;
use App\Infrastructure\Queue\LaravelRedisDeliveryQueue;
use App\Infrastructure\Queue\ProcessDeliveryJob;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;
use Predis\Connection\ConnectionException;
use Predis\Connection\Resource\Exception\StreamInitException;
use Predis\Response\ServerException;
use RedisException;
use Tests\Support\FrozenClock;
use Tests\TestCase;

final class DeliveryQueueRedisIntegrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_the_real_redis_job_is_published_only_after_the_delivery_is_visible_to_an_independent_mysql_connection(): void
    {
        $this->requireMySqlAndRedis();
        $this->clearDeliveriesQueue();

        $firstEndpointId = $this->createEndpoint('First commit boundary endpoint');
        $secondEndpointId = $this->createEndpoint('Second commit boundary endpoint');
        $this->replaceSubscriptions($firstEndpointId, ['order.paid']);
        $this->replaceSubscriptions($secondEndpointId, ['order.paid']);
        $visibilityConnection = $this->createConnection('delivery_queue_visibility');
        $realQueue = app(DeliveryQueue::class);
        $deliveryWasCommitted = false;

        $this->app->instance(
            DeliveryQueue::class,
            new class($realQueue, $visibilityConnection, $deliveryWasCommitted) implements DeliveryQueue
            {
                public function __construct(
                    private DeliveryQueue $queue,
                    private string $visibilityConnection,
                    bool &$deliveryWasCommitted,
                ) {
                    $this->deliveryWasCommitted = &$deliveryWasCommitted;
                }

                private bool $deliveryWasCommitted;

                public function enqueue(DeliveryId $deliveryId): void
                {
                    $this->deliveryWasCommitted = DB::connection($this->visibilityConnection)
                        ->table('deliveries')
                        ->where('public_id', $deliveryId->toString())
                        ->exists();

                    $this->queue->enqueue($deliveryId);
                }

                public function schedule(DeliveryId $deliveryId, \DateTimeImmutable $availableAt): void
                {
                    $this->queue->schedule($deliveryId, $availableAt);
                }
            },
        );

        try {
            $response = $this->postEvent('order.paid')->assertCreated();
            $eventId = (string) $response->json('data.id');
            $deliveryIds = $this->deliveryIdsForEvent($eventId);

            self::assertTrue($deliveryWasCommitted);
            self::assertSame(2, $this->queueLength());

            $payloads = Redis::connection()->lRange('queues:deliveries', 0, -1);
            self::assertCount(2, $payloads);
            self::assertStringContainsString($deliveryIds[0], $payloads[0]);
            self::assertStringContainsString($deliveryIds[1], $payloads[1]);
            self::assertStringNotContainsString('order_1001', implode('', $payloads));
            self::assertStringNotContainsString('https://example.test/webhooks', implode('', $payloads));
        } finally {
            DB::purge($visibilityConnection);
            $this->clearDeliveriesQueue();
        }
    }

    public function test_a_real_worker_records_an_unsafe_target_failure_without_queue_retry(): void
    {
        $this->requireMySqlAndRedis();
        $this->clearDeliveriesQueue();

        $endpointId = $this->createEndpoint('Worker endpoint', 'http://127.0.0.1/redis-queue');
        $this->replaceSubscriptions($endpointId, ['order.paid']);
        $eventId = (string) $this->postEvent('order.paid')->assertCreated()->json('data.id');
        $deliveryId = $this->deliveryIdForEvent($eventId);

        self::assertSame(1, $this->queueLength());
        self::assertSame(0, Artisan::call('queue:work', [
            'connection' => 'redis',
            '--queue' => 'deliveries',
            '--once' => true,
            '--tries' => 1,
        ]));
        self::assertSame(0, $this->queueLength());
        $this->assertDatabaseHas('deliveries', [
            'public_id' => $deliveryId,
            'status' => 'failed',
        ]);
        $this->assertDatabaseHas('delivery_attempts', [
            'delivery_id' => DB::table('deliveries')->where('public_id', $deliveryId)->value('id'),
            'attempt_number' => 1,
            'status' => 'failed',
            'failure_type' => 'unsafe_target',
        ]);
    }

    public function test_real_redis_connection_failures_are_translated_and_logged_as_publication_failures(): void
    {
        $unavailableConnection = 'issue12_unavailable';
        config([
            "database.redis.{$unavailableConnection}" => array_merge(
                config('database.redis.default'),
                ['host' => '127.0.0.1', 'port' => '1'],
            ),
            'queue.connections.redis.connection' => $unavailableConnection,
        ]);
        app()->forgetInstance('redis');
        app()->forgetInstance('queue');
        Log::spy();
        $deliveryId = DeliveryId::fromString('adb4d301-f44a-4dab-a545-6f9046cbeb6f');

        try {
            app(DeliveryQueue::class)->enqueue($deliveryId);
            self::fail('An unavailable Redis connection must be translated.');
        } catch (DeliveryQueueUnavailable $exception) {
            self::assertSame($deliveryId->toString(), $exception->deliveryId->toString());
        }

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('Delivery queue publication failed.', \Mockery::on(
                static fn (array $context): bool => $context['delivery_id'] === $deliveryId->toString()
                    && $context['queue'] === 'deliveries'
                    && $context['connection'] === 'redis'
                    && in_array($context['exception'], [
                        ConnectionException::class,
                        RedisException::class,
                        StreamInitException::class,
                    ], true)
                    && is_string($context['message']),
            ));
    }

    public function test_real_publisher_translates_redis_server_errors_and_releases_the_unique_lock(): void
    {
        $this->requireMySqlAndRedis();
        $deliveryId = DeliveryId::fromString('adb4d301-f44a-4dab-a545-6f9046cbeb6f');
        Log::spy();
        $dispatcher = $this->replaceDispatcherWithServerFailure();

        try {
            app(LaravelRedisDeliveryQueue::class)->enqueue($deliveryId);
            self::fail('A Redis server publication error must be translated.');
        } catch (DeliveryQueueUnavailable $exception) {
            self::assertSame($deliveryId->toString(), $exception->deliveryId->toString());
            self::assertInstanceOf(ServerException::class, $exception->getPrevious());
        } finally {
            $this->app->instance(Dispatcher::class, $dispatcher);
        }

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('Delivery queue publication failed.', \Mockery::on(
                static fn (array $context): bool => $context['delivery_id'] === $deliveryId->toString()
                    && $context['queue'] === 'deliveries'
                    && $context['connection'] === 'redis'
                    && $context['exception'] === ServerException::class
                    && $context['message'] === 'READONLY simulated publication failure'
                    && ! array_key_exists('payload', $context),
            ));

        $this->assertUniqueLockCanBeAcquired($deliveryId);
    }

    public function test_redis_server_publication_failure_after_commit_returns_201_and_recovery_immediately_enqueues_the_delivery(): void
    {
        $this->requireMySqlAndRedis();
        $this->clearDeliveriesQueue();

        $endpointId = $this->createEndpoint('Server failure endpoint');
        $this->replaceSubscriptions($endpointId, ['order.paid']);
        $dispatcher = $this->replaceDispatcherWithServerFailure();

        try {
            $response = $this->postEvent('order.paid')->assertCreated();
        } finally {
            $this->app->instance(Dispatcher::class, $dispatcher);
        }

        $eventId = (string) $response->json('data.id');
        $deliveryId = $this->deliveryIdForEvent($eventId);

        $this->assertDatabaseCount('events', 1);
        $this->assertDatabaseHas('deliveries', [
            'public_id' => $deliveryId,
            'status' => 'pending',
        ]);
        self::assertSame(0, $this->queueLength());

        $result = app(EnqueuePendingDeliveries::class)->handle(100);

        self::assertSame(1, $result->enqueued);
        self::assertSame(0, $result->failed);
        self::assertSame(1, $this->queueLength());
        self::assertStringContainsString($deliveryId, Redis::connection()->lIndex('queues:deliveries', 0));

        $this->releaseUniqueLock(DeliveryId::fromString($deliveryId));
        $this->clearDeliveriesQueue();
    }

    public function test_real_unique_dispatch_keeps_only_one_queued_job_for_duplicate_delivery_enqueue_requests(): void
    {
        $this->requireMySqlAndRedis();
        $this->clearDeliveriesQueue();

        $endpointId = $this->createEndpoint('Unique dispatch endpoint');
        $eventId = (string) $this->postEvent('order.paid')->assertCreated()->json('data.id');
        $delivery = app(CreateDelivery::class)->handle($eventId, $endpointId);
        $deliveryId = DeliveryId::fromString($delivery->id);
        $this->releaseUniqueLock($deliveryId);

        try {
            app(DeliveryQueue::class)->enqueue($deliveryId);
            app(DeliveryQueue::class)->enqueue($deliveryId);

            self::assertSame(1, $this->queueLength());
            self::assertStringContainsString(
                $deliveryId->toString(),
                Redis::connection()->lIndex('queues:deliveries', 0),
            );
        } finally {
            $this->releaseUniqueLock($deliveryId);
            $this->clearDeliveriesQueue();
        }
    }

    public function test_a_real_worker_can_schedule_a_retry_for_its_own_delivery_after_the_unique_lock_is_released(): void
    {
        $this->requireMySqlAndRedis();
        $this->clearDeliveriesQueue();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-08-31T12:00:00+00:00'));
        $this->app->instance(Clock::class, $clock);
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
        $endpointId = $this->createEndpoint('Retry unique lifecycle endpoint');
        $eventId = (string) $this->postEvent('order.paid')->assertCreated()->json('data.id');
        $deliveryId = DeliveryId::fromString(app(CreateDelivery::class)->handle($eventId, $endpointId)->id);

        try {
            app(DeliveryQueue::class)->enqueue($deliveryId);
            self::assertSame(1, $this->queueLength());
            self::assertSame(0, Artisan::call('queue:work', [
                'connection' => 'redis',
                '--queue' => 'deliveries',
                '--once' => true,
                '--tries' => 1,
            ]));

            $this->assertDatabaseHas('deliveries', [
                'public_id' => $deliveryId->toString(),
                'status' => 'retry_scheduled',
            ]);
            self::assertSame(1, (int) Redis::connection()->zCard('queues:deliveries:delayed'));
            self::assertStringContainsString(
                $deliveryId->toString(),
                (string) Redis::connection()->zRange('queues:deliveries:delayed', 0, 0)[0],
            );
        } finally {
            $this->releaseUniqueLock($deliveryId);
            $this->clearDeliveriesQueue();
        }
    }

    private function replaceDispatcherWithServerFailure(): Dispatcher
    {
        $originalDispatcher = app(Dispatcher::class);
        $dispatcher = \Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->andThrow(new ServerException('READONLY simulated publication failure'));

        $this->app->instance(Dispatcher::class, $dispatcher);

        return $originalDispatcher;
    }

    private function assertUniqueLockCanBeAcquired(DeliveryId $deliveryId): void
    {
        $job = new ProcessDeliveryJob($deliveryId->toString());
        $uniqueLock = new UniqueLock(app(Cache::class));

        self::assertTrue($uniqueLock->acquire($job));
        $uniqueLock->release($job);
    }

    private function releaseUniqueLock(DeliveryId $deliveryId): void
    {
        (new UniqueLock(app(Cache::class)))->release(new ProcessDeliveryJob($deliveryId->toString()));
    }

    private function requireMySqlAndRedis(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('This integration test requires MySQL and Redis.');
        }

        try {
            Redis::connection()->ping();
        } catch (ConnectionException) {
            $this->markTestSkipped('This integration test requires an available Redis service.');
        }
    }

    private function createConnection(string $name): string
    {
        config([
            "database.connections.{$name}" => config('database.connections.mysql'),
        ]);
        DB::purge($name);

        return $name;
    }

    private function clearDeliveriesQueue(): void
    {
        Redis::connection()->del('queues:deliveries', 'queues:deliveries:delayed', 'queues:deliveries:reserved');
    }

    private function queueLength(): int
    {
        return Redis::connection()->lLen('queues:deliveries');
    }

    private function createEndpoint(string $name, string $url = 'https://example.test/webhooks/redis-queue'): string
    {
        $response = $this->postJson('/api/endpoints', [
            'name' => $name,
            'url' => $url,
        ])->assertCreated();

        return (string) $response->json('data.id');
    }

    /**
     * @param  list<string>  $types
     */
    private function replaceSubscriptions(string $endpointId, array $types): void
    {
        $this->putJson("/api/endpoints/{$endpointId}/subscriptions", [
            'types' => $types,
        ])->assertOk();
    }

    private function postEvent(string $type): TestResponse
    {
        return $this->postJson('/api/events', [
            'type' => $type,
            'payload' => (object) ['order_id' => 'order_1001'],
        ]);
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

    /**
     * @return list<string>
     */
    private function deliveryIdsForEvent(string $eventId): array
    {
        return DB::table('deliveries')
            ->join('events', 'deliveries.event_id', '=', 'events.id')
            ->where('events.public_id', $eventId)
            ->orderBy('deliveries.id')
            ->pluck('deliveries.public_id')
            ->all();
    }
}

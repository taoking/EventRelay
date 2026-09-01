<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Application\Clock\Clock;
use App\Application\Delivery\CreateDelivery;
use App\Application\Delivery\DeliveryTransport;
use App\Application\Delivery\DeliveryTransportUnavailable;
use App\Application\Delivery\PublishDeliveryOutbox;
use App\Application\Delivery\WebhookRequest;
use App\Application\Delivery\WebhookResponse;
use App\Application\Delivery\WebhookTarget;
use App\Application\Delivery\WebhookTargetResolver;
use App\Application\Delivery\WebhookTransport;
use App\Domain\Delivery\DeliveryId;
use App\Infrastructure\Queue\LaravelRedisDeliveryTransport;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;
use Predis\Connection\ConnectionException;
use Predis\Connection\NodeConnectionInterface;
use Predis\Connection\Resource\Exception\StreamInitException;
use Predis\Response\ServerException;
use Predis\TimeoutException;
use RedisException;
use Tests\Support\FrozenClock;
use Tests\TestCase;

final class DeliveryQueueRedisIntegrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_the_real_redis_job_is_published_by_outbox_only_after_the_delivery_is_visible_to_an_independent_mysql_connection(): void
    {
        $this->requireMySqlAndRedis();
        $this->clearDeliveriesQueue();

        $firstEndpointId = $this->createEndpoint('First commit boundary endpoint');
        $secondEndpointId = $this->createEndpoint('Second commit boundary endpoint');
        $this->replaceSubscriptions($firstEndpointId, ['order.paid']);
        $this->replaceSubscriptions($secondEndpointId, ['order.paid']);
        $visibilityConnection = $this->createConnection('delivery_queue_visibility');
        $realQueue = app(DeliveryTransport::class);
        $deliveryWasCommitted = false;

        $this->app->instance(
            DeliveryTransport::class,
            new class($realQueue, $visibilityConnection, $deliveryWasCommitted) implements DeliveryTransport
            {
                public function __construct(
                    private DeliveryTransport $queue,
                    private string $visibilityConnection,
                    bool &$deliveryWasCommitted,
                ) {
                    $this->deliveryWasCommitted = &$deliveryWasCommitted;
                }

                private bool $deliveryWasCommitted;

                public function publish(DeliveryId $deliveryId): void
                {
                    $this->deliveryWasCommitted = DB::connection($this->visibilityConnection)
                        ->table('deliveries')
                        ->where('public_id', $deliveryId->toString())
                        ->exists();

                    $this->queue->publish($deliveryId);
                }
            },
        );

        try {
            $response = $this->postEvent('order.paid')->assertCreated();
            $eventId = (string) $response->json('data.id');
            $deliveryIds = $this->deliveryIdsForEvent($eventId);

            self::assertSame(0, $this->queueLength());
            $result = app(PublishDeliveryOutbox::class)->handle(100);

            self::assertSame(2, $result->published);
            self::assertSame(0, $result->failed);
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

        self::assertSame(1, app(PublishDeliveryOutbox::class)->handle(100)->published);

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
            app(DeliveryTransport::class)->publish($deliveryId);
            self::fail('An unavailable Redis connection must be translated.');
        } catch (DeliveryTransportUnavailable $exception) {
            self::assertSame($deliveryId->toString(), $exception->deliveryId->toString());
        }

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('Delivery transport publication failed.', \Mockery::on(
                static fn (array $context): bool => $context['delivery_id'] === $deliveryId->toString()
                    && $context['queue'] === 'deliveries'
                    && $context['transport'] === 'redis'
                    && in_array($context['exception'], [
                        ConnectionException::class,
                        RedisException::class,
                        StreamInitException::class,
                    ], true)
                    && is_string($context['message']),
            ));
    }

    public function test_real_publisher_translates_redis_server_errors_without_queue_level_lock_cleanup(): void
    {
        $this->requireMySqlAndRedis();
        $deliveryId = DeliveryId::fromString('adb4d301-f44a-4dab-a545-6f9046cbeb6f');
        Log::spy();
        $dispatcher = $this->replaceDispatcherWithServerFailure();

        try {
            app(LaravelRedisDeliveryTransport::class)->publish($deliveryId);
            self::fail('A Redis server publication error must be translated.');
        } catch (DeliveryTransportUnavailable $exception) {
            self::assertSame($deliveryId->toString(), $exception->deliveryId->toString());
            self::assertInstanceOf(ServerException::class, $exception->getPrevious());
        } finally {
            $this->app->instance(Dispatcher::class, $dispatcher);
        }

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('Delivery transport publication failed.', \Mockery::on(
                static fn (array $context): bool => $context['delivery_id'] === $deliveryId->toString()
                    && $context['queue'] === 'deliveries'
                    && $context['transport'] === 'redis'
                    && $context['exception'] === ServerException::class
                    && $context['message'] === 'READONLY simulated publication failure'
                    && ! array_key_exists('payload', $context),
            ));

    }

    public function test_real_publisher_translates_predis_timeouts_as_recoverable_publication_failures(): void
    {
        $this->requireMySqlAndRedis();
        $deliveryId = DeliveryId::fromString('adb4d301-f44a-4dab-a545-6f9046cbeb6f');
        $timeout = new TimeoutException(\Mockery::mock(NodeConnectionInterface::class));
        Log::spy();
        $dispatcher = $this->replaceDispatcherWithPublicationFailure($timeout);

        try {
            app(LaravelRedisDeliveryTransport::class)->publish($deliveryId);
            self::fail('A Redis publication timeout must be translated.');
        } catch (DeliveryTransportUnavailable $exception) {
            self::assertSame($deliveryId->toString(), $exception->deliveryId->toString());
            self::assertSame($timeout, $exception->getPrevious());
        } finally {
            $this->app->instance(Dispatcher::class, $dispatcher);
        }

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('Delivery transport publication failed.', \Mockery::on(
                static fn (array $context): bool => $context['delivery_id'] === $deliveryId->toString()
                    && $context['queue'] === 'deliveries'
                    && $context['transport'] === 'redis'
                    && $context['exception'] === TimeoutException::class
                    && $context['message'] === 'Operation has timed out',
            ));
    }

    public function test_redis_server_publication_failure_leaves_the_committed_outbox_message_recoverable(): void
    {
        $this->requireMySqlAndRedis();
        $this->clearDeliveriesQueue();

        $endpointId = $this->createEndpoint('Server failure endpoint');
        $this->replaceSubscriptions($endpointId, ['order.paid']);
        $dispatcher = $this->replaceDispatcherWithServerFailure();

        try {
            $response = $this->postEvent('order.paid')->assertCreated();
            $publication = app(PublishDeliveryOutbox::class)->handle(100);
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
        self::assertSame(0, $publication->published);
        self::assertSame(1, $publication->failed);
        $this->assertDatabaseHas('delivery_outbox_messages', ['status' => 'pending', 'last_error_code' => 'redis_unavailable']);

        $result = app(PublishDeliveryOutbox::class)->handle(100);

        self::assertSame(1, $result->published);
        self::assertSame(0, $result->failed);
        self::assertSame(1, $this->queueLength());
        self::assertStringContainsString($deliveryId, Redis::connection()->lIndex('queues:deliveries', 0));

        $this->clearDeliveriesQueue();
    }

    public function test_duplicate_queue_publications_physically_push_duplicate_jobs_for_the_delivery_claim_to_absorb(): void
    {
        $this->requireMySqlAndRedis();
        $this->clearDeliveriesQueue();

        $endpointId = $this->createEndpoint('Unique dispatch endpoint');
        $eventId = (string) $this->postEvent('order.paid')->assertCreated()->json('data.id');
        $delivery = app(CreateDelivery::class)->handle($eventId, $endpointId);
        $deliveryId = DeliveryId::fromString($delivery->id);

        try {
            app(DeliveryTransport::class)->publish($deliveryId);
            app(DeliveryTransport::class)->publish($deliveryId);

            self::assertSame(2, $this->queueLength());
            self::assertStringContainsString(
                $deliveryId->toString(),
                Redis::connection()->lIndex('queues:deliveries', 0),
            );
            self::assertStringContainsString(
                $deliveryId->toString(),
                Redis::connection()->lIndex('queues:deliveries', 1),
            );
        } finally {
            $this->clearDeliveriesQueue();
        }
    }

    public function test_a_real_worker_creates_a_retry_outbox_intent_without_a_queue_level_lock(): void
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
            app(DeliveryTransport::class)->publish($deliveryId);
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
            $this->assertDatabaseHas('delivery_outbox_messages', [
                'dedupe_key' => 'delivery:'.$deliveryId->toString().':attempt:2',
                'attempt_number' => 2,
                'status' => 'pending',
            ]);
            self::assertSame(0, app(PublishDeliveryOutbox::class)->handle(100)->published);
            self::assertSame(0, $this->queueLength());
            $clock->set(new \DateTimeImmutable('2026-08-31T12:00:10+00:00'));
            self::assertSame(1, app(PublishDeliveryOutbox::class)->handle(100)->published);
            self::assertSame(1, $this->queueLength());
            self::assertStringContainsString(
                $deliveryId->toString(),
                (string) Redis::connection()->lIndex('queues:deliveries', 0),
            );
        } finally {
            $this->clearDeliveriesQueue();
        }
    }

    public function test_real_redis_payload_does_not_contain_the_raw_event_ingress_idempotency_key(): void
    {
        $this->requireMySqlAndRedis();
        $this->clearDeliveriesQueue();

        $endpointId = $this->createEndpoint('Event ingress key leak endpoint');
        $this->replaceSubscriptions($endpointId, ['order.paid']);
        $rawKey = 'event-ingress-redis-key-leak-fixture';
        $eventId = (string) $this->postJson('/api/events', [
            'type' => 'order.paid',
            'payload' => (object) ['source' => 'event-ingress-redis-key-leak'],
        ], ['Idempotency-Key' => $rawKey])->assertCreated()->json('data.id');
        $deliveryId = $this->deliveryIdForEvent($eventId);

        try {
            self::assertSame(1, app(PublishDeliveryOutbox::class)->handle(100)->published);
            $payload = (string) Redis::connection()->lIndex('queues:deliveries', 0);

            self::assertStringContainsString($deliveryId, $payload);
            self::assertStringNotContainsString($rawKey, $payload);
        } finally {
            $this->clearDeliveriesQueue();
        }
    }

    private function replaceDispatcherWithServerFailure(): Dispatcher
    {
        return $this->replaceDispatcherWithPublicationFailure(new ServerException('READONLY simulated publication failure'));
    }

    private function replaceDispatcherWithPublicationFailure(\Throwable $exception): Dispatcher
    {
        $originalDispatcher = app(Dispatcher::class);
        $dispatcher = \Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->andThrow($exception);

        $this->app->instance(Dispatcher::class, $dispatcher);

        return $originalDispatcher;
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

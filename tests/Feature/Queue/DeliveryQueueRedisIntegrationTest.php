<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Application\Delivery\DeliveryQueue;
use App\Application\Delivery\DeliveryQueueUnavailable;
use App\Domain\Delivery\DeliveryId;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;
use Predis\Connection\ConnectionException;
use Predis\Connection\Resource\Exception\StreamInitException;
use RedisException;
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

    public function test_a_real_worker_consumes_the_redis_job_and_keeps_the_delivery_pending(): void
    {
        $this->requireMySqlAndRedis();
        $this->clearDeliveriesQueue();

        $endpointId = $this->createEndpoint('Worker endpoint');
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
            'status' => 'pending',
        ]);
        self::assertFalse(
            \Schema::hasTable('delivery_attempts'),
            'Issue #12 worker must not create a DeliveryAttempt table.',
        );
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
        Redis::connection()->del('queues:deliveries');
    }

    private function queueLength(): int
    {
        return Redis::connection()->lLen('queues:deliveries');
    }

    private function createEndpoint(string $name): string
    {
        $response = $this->postJson('/api/endpoints', [
            'name' => $name,
            'url' => 'https://example.test/webhooks/redis-queue',
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

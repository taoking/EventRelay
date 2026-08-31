<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMq;

use App\Application\Delivery\DeliveryTransport;
use App\Application\Delivery\PublishDeliveryOutbox;
use App\Application\Delivery\WebhookRequest;
use App\Application\Delivery\WebhookResponse;
use App\Application\Delivery\WebhookTarget;
use App\Application\Delivery\WebhookTargetResolver;
use App\Application\Delivery\WebhookTransport;
use App\Domain\Delivery\DeliveryId;
use App\Infrastructure\RabbitMq\RabbitMqConfiguration;
use App\Infrastructure\RabbitMq\RabbitMqTopology;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Tests\TestCase;

final class RabbitMqDeliveryConsumerLifecycleTest extends TestCase
{
    use DatabaseMigrations;

    public function test_one_continuous_consumer_survives_idle_timeouts_consumes_a_later_message_and_stops_gracefully(): void
    {
        $this->requireMySqlRabbitMqAndProcessControl();
        $this->purgeQueue();
        $this->useRabbitTransport();
        $deliveryId = $this->createPendingDelivery();
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$parent, $child] = $pair;
        $pid = pcntl_fork();

        if ($pid === -1) {
            self::fail('Unable to fork the continuous RabbitMQ consumer.');
        }
        if ($pid === 0) {
            fclose($parent);
            $this->runContinuousConsumer($child);
        }

        fclose($child);
        stream_set_timeout($parent, 15);
        $stopped = false;

        try {
            self::assertSame("starting\n", fgets($parent));
            self::assertTrue($this->waitForConsumer(), (string) fgets($parent));
            sleep(3);
            self::assertTrue(posix_kill($pid, 0));

            $this->reconnectAfterFork();
            self::assertSame(1, app(PublishDeliveryOutbox::class)->handle(100)->published);
            self::assertTrue($this->waitForDeliveryStatus($deliveryId, 'succeeded'));
            self::assertSame(0, $this->queueMessageCount());
            self::assertSame(1, DB::table('delivery_attempts')->where('attempt_number', 1)->count());

            self::assertTrue(posix_kill($pid, SIGTERM));
            self::assertSame("exit:0\n", fgets($parent));
            pcntl_waitpid($pid, $status);
            self::assertSame(0, pcntl_wexitstatus($status));
            $stopped = true;
        } finally {
            if (! $stopped) {
                posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $status, WNOHANG);
            }

            fclose($parent);
        }
    }

    public function test_unknown_processor_exception_is_not_acknowledged_and_the_message_can_be_redelivered(): void
    {
        $this->requireMySqlRabbitMqAndProcessControl();
        $this->purgeQueue();
        $this->useRabbitTransport();
        $deliveryId = $this->createPendingDelivery();
        app(DeliveryTransport::class)->publish(DeliveryId::fromString($deliveryId));
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$parent, $child] = $pair;
        $pid = pcntl_fork();

        if ($pid === -1) {
            self::fail('Unable to fork the failing RabbitMQ consumer.');
        }
        if ($pid === 0) {
            fclose($parent);
            $this->runUnknownProcessorConsumer($child);
        }

        fclose($child);
        stream_set_timeout($parent, 15);

        try {
            self::assertSame("exception:LogicException\n", fgets($parent));
            pcntl_waitpid($pid, $status);
            self::assertSame(1, pcntl_wexitstatus($status));

            $this->reconnectAfterFork();
            self::assertTrue($this->waitForRedelivery());
            $this->assertDatabaseHas('deliveries', ['public_id' => $deliveryId, 'status' => 'processing']);
            $this->assertDatabaseHas('delivery_attempts', ['attempt_number' => 1, 'status' => 'started']);
            self::assertSame(1, DB::table('delivery_attempts')->count());

            self::assertSame(0, Artisan::call('deliveries:consume-rabbitmq', ['--once' => true]));
            self::assertSame(0, $this->queueMessageCount());
            self::assertSame(1, DB::table('delivery_attempts')->count());
        } finally {
            fclose($parent);
        }
    }

    /** @return never-return */
    private function runContinuousConsumer(mixed $socket): void
    {
        try {
            $this->reconnectAfterFork();
            $this->bindSuccessfulWebhookTransport();
            fwrite($socket, "starting\n");
            $exit = Artisan::call('deliveries:consume-rabbitmq');
            fwrite($socket, "exit:{$exit}\n");
            fclose($socket);
            exit($exit);
        } catch (\Throwable $exception) {
            fwrite($socket, 'error:'.$exception::class."\n");
            fclose($socket);
            exit(1);
        }
    }

    /** @return never-return */
    private function runUnknownProcessorConsumer(mixed $socket): void
    {
        try {
            $this->reconnectAfterFork();
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
                    throw new \LogicException('Unexpected processor failure.');
                }
            });
            Artisan::call('deliveries:consume-rabbitmq');
            fclose($socket);
            exit(1);
        } catch (\LogicException $exception) {
            fwrite($socket, "exception:LogicException\n");
            fclose($socket);
            exit(1);
        } catch (\Throwable $exception) {
            fwrite($socket, 'error:'.$exception::class."\n");
            fclose($socket);
            exit(1);
        }
    }

    private function bindSuccessfulWebhookTransport(): void
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
                return new WebhookResponse(204, 1);
            }
        });
    }

    private function useRabbitTransport(): void
    {
        config(['delivery.transport' => 'rabbitmq']);
        $this->app->forgetInstance(DeliveryTransport::class);
    }

    private function configuration(): RabbitMqConfiguration
    {
        return RabbitMqConfiguration::fromConfig(config('delivery.rabbitmq'));
    }

    private function connection(): AMQPStreamConnection
    {
        $config = $this->configuration();

        return new AMQPStreamConnection(
            $config->host,
            $config->port,
            $config->user,
            $config->password,
            $config->vhost,
            false,
            'AMQPLAIN',
            null,
            'en_US',
            $config->timeout,
            $config->timeout,
        );
    }

    private function purgeQueue(): void
    {
        $connection = $this->connection();
        $channel = $connection->channel();
        (new RabbitMqTopology($this->configuration()))->declare($channel);
        $channel->queue_purge($this->configuration()->queue);
        $channel->close();
        $connection->close();
    }

    private function queueMessageCount(): int
    {
        $connection = $this->connection();
        $channel = $connection->channel();
        (new RabbitMqTopology($this->configuration()))->declare($channel);
        $declared = $channel->queue_declare($this->configuration()->queue, true);
        $channel->close();
        $connection->close();

        return (int) $declared[1];
    }

    private function queueConsumerCount(): int
    {
        $connection = $this->connection();
        $channel = $connection->channel();
        (new RabbitMqTopology($this->configuration()))->declare($channel);
        $declared = $channel->queue_declare($this->configuration()->queue, true);
        $channel->close();
        $connection->close();

        return (int) $declared[2];
    }

    private function waitForConsumer(): bool
    {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            if ($this->queueConsumerCount() === 1) {
                return true;
            }

            usleep(100_000);
        }

        return false;
    }

    private function waitForDeliveryStatus(string $deliveryId, string $status): bool
    {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            if (DB::table('deliveries')->where('public_id', $deliveryId)->value('status') === $status) {
                return true;
            }

            usleep(100_000);
        }

        return false;
    }

    private function waitForRedelivery(): bool
    {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            if ($this->queueMessageCount() > 0) {
                return true;
            }

            usleep(100_000);
        }

        return false;
    }

    private function reconnectAfterFork(): void
    {
        DB::disconnect();
        DB::purge();
        DB::connection()->getPdo();
    }

    private function requireMySqlRabbitMqAndProcessControl(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('This integration test requires MySQL/InnoDB, RabbitMQ, pcntl and posix.');
        }

        try {
            $connection = $this->connection();
            $connection->close();
        } catch (\Throwable) {
            $this->markTestSkipped('This integration test requires an available RabbitMQ service.');
        }
    }

    private function createPendingDelivery(): string
    {
        $endpointId = (string) $this->postJson('/api/endpoints', [
            'name' => 'RabbitMQ continuous consumer endpoint',
            'url' => 'https://receiver.example/rabbit-lifecycle',
        ])->assertCreated()->json('data.id');
        $this->putJson("/api/endpoints/{$endpointId}/subscriptions", ['types' => ['order.paid']])->assertOk();
        $eventId = (string) $this->postJson('/api/events', [
            'type' => 'order.paid',
            'payload' => (object) [],
        ])->assertCreated()->json('data.id');

        return (string) DB::table('deliveries')
            ->join('events', 'deliveries.event_id', '=', 'events.id')
            ->where('events.public_id', $eventId)
            ->value('deliveries.public_id');
    }
}

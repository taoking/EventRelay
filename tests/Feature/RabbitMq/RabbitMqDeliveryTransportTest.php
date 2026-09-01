<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMq;

use App\Application\Clock\Clock;
use App\Application\Delivery\DeliveryOutboxPublisherRepository;
use App\Application\Delivery\DeliveryTransport;
use App\Application\Delivery\DeliveryTransportUnavailable;
use App\Application\Delivery\PublishDeliveryOutbox;
use App\Application\Delivery\RecoverStaleDelivery;
use App\Application\Delivery\WebhookRequest;
use App\Application\Delivery\WebhookResponse;
use App\Application\Delivery\WebhookTarget;
use App\Application\Delivery\WebhookTargetResolver;
use App\Application\Delivery\WebhookTransport;
use App\Domain\Delivery\DeliveryId;
use App\Infrastructure\RabbitMq\PhpAmqpLibRabbitMqDeliveryPublisher;
use App\Infrastructure\RabbitMq\RabbitMqConfiguration;
use App\Infrastructure\RabbitMq\RabbitMqDeliveryConsumer;
use App\Infrastructure\RabbitMq\RabbitMqDeliveryTransport;
use App\Infrastructure\RabbitMq\RabbitMqTopology;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Tests\Support\FrozenClock;
use Tests\TestCase;

final class RabbitMqDeliveryTransportTest extends TestCase
{
    use DatabaseMigrations;

    public function test_confirmed_publication_uses_the_canonical_uuid_only_envelope_and_manual_consumer_executes_it(): void
    {
        $this->requireMySqlAndRabbitMq();
        $this->purgeQueue();
        $this->useRabbitTransport();
        $sent = 0;
        $this->app->instance(WebhookTargetResolver::class, new class implements WebhookTargetResolver
        {
            public function resolve(string $url): WebhookTarget
            {
                return new WebhookTarget($url, 'receiver.example', 443, '1.1.1.1');
            }
        });
        $this->app->instance(WebhookTransport::class, new class($sent) implements WebhookTransport
        {
            public function __construct(private int &$sent) {}

            public function send(WebhookTarget $target, WebhookRequest $request): WebhookResponse
            {
                $this->sent++;

                return new WebhookResponse(204, 1);
            }
        });

        $endpointId = $this->createEndpoint('Rabbit confirmed endpoint');
        $this->replaceSubscriptions($endpointId, ['order.paid']);
        $eventId = (string) $this->postJson('/api/events', [
            'type' => 'order.paid',
            'payload' => (object) ['order_id' => 'rabbit-1001'],
        ])->assertCreated()->json('data.id');
        $deliveryId = (string) DB::table('deliveries')
            ->join('events', 'deliveries.event_id', '=', 'events.id')
            ->where('events.public_id', $eventId)
            ->value('deliveries.public_id');

        self::assertSame(1, app(PublishDeliveryOutbox::class)->handle(100)->published);
        $message = $this->getOneMessage();
        self::assertInstanceOf(AMQPMessage::class, $message);
        self::assertSame('{"v":1,"type":"delivery.process","delivery_id":"'.$deliveryId.'"}', $message->getBody());
        self::assertSame('application/json', $message->get('content_type'));
        self::assertSame(2, $message->get('delivery_mode'));
        $message->nack(true);

        self::assertSame(0, Artisan::call('deliveries:consume-rabbitmq', ['--once' => true]));
        self::assertSame(1, $sent);
        $this->assertDatabaseHas('deliveries', ['public_id' => $deliveryId, 'status' => 'succeeded']);
        $this->assertDatabaseHas('delivery_attempts', ['attempt_number' => 1, 'status' => 'succeeded']);
    }

    public function test_malformed_envelope_is_rejected_without_requeue_or_delivery_execution(): void
    {
        $this->requireMySqlAndRabbitMq();
        $this->purgeQueue();
        $this->publishRaw('{"v":1,"type":"delivery.process","delivery_id":"not-a-uuid","unexpected":true}');

        self::assertSame(0, Artisan::call('deliveries:consume-rabbitmq', ['--once' => true]));
        self::assertSame(0, $this->queueMessageCount());
        $this->assertDatabaseCount('delivery_attempts', 0);
    }

    public function test_future_outbox_rows_are_not_claimed_or_published_until_they_are_due(): void
    {
        $this->requireMySqlAndRabbitMq();
        $this->purgeQueue();
        $this->useRabbitTransport();
        $deliveryId = $this->createPendingDelivery();
        DB::table('delivery_outbox_messages')->where('attempt_number', 1)->update([
            'available_at' => now()->addMinute(),
        ]);

        $result = app(PublishDeliveryOutbox::class)->handle(100);

        self::assertSame(0, $result->published);
        self::assertSame(0, $this->queueMessageCount());
        $this->assertDatabaseHas('delivery_outbox_messages', [
            'attempt_number' => 1,
            'status' => 'pending',
            'publication_attempts' => 0,
            'claim_token' => null,
        ]);
        self::assertNotSame('', $deliveryId);
    }

    public function test_duplicate_confirmed_rabbit_messages_are_absorbed_by_the_delivery_claim_before_a_second_http_execution(): void
    {
        $this->requireMySqlAndRabbitMq();
        $this->purgeQueue();
        $this->useRabbitTransport();
        $sent = 0;
        $this->app->instance(WebhookTargetResolver::class, new class implements WebhookTargetResolver
        {
            public function resolve(string $url): WebhookTarget
            {
                return new WebhookTarget($url, 'receiver.example', 443, '1.1.1.1');
            }
        });
        $this->app->instance(WebhookTransport::class, new class($sent) implements WebhookTransport
        {
            public function __construct(private int &$sent) {}

            public function send(WebhookTarget $target, WebhookRequest $request): WebhookResponse
            {
                $this->sent++;

                return new WebhookResponse(200, 1);
            }
        });
        $deliveryId = $this->createPendingDelivery();

        app(DeliveryTransport::class)->publish(DeliveryId::fromString($deliveryId));
        app(DeliveryTransport::class)->publish(DeliveryId::fromString($deliveryId));

        self::assertSame(2, $this->queueMessageCount());
        self::assertSame(0, Artisan::call('deliveries:consume-rabbitmq', ['--once' => true]));
        self::assertSame(0, Artisan::call('deliveries:consume-rabbitmq', ['--once' => true]));
        self::assertSame(1, $sent);
        $this->assertDatabaseCount('delivery_attempts', 1);
        $this->assertDatabaseHas('deliveries', ['public_id' => $deliveryId, 'status' => 'succeeded']);
    }

    public function test_rabbit_physical_publication_respects_the_mysql_retry_due_times_and_maximum_attempt_budget(): void
    {
        $this->requireMySqlAndRabbitMq();
        $this->purgeQueue();
        $this->useRabbitTransport();
        $clock = new FrozenClock(new DateTimeImmutable('2026-09-10T12:00:00+00:00'));
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
            private int $calls = 0;

            public function send(WebhookTarget $target, WebhookRequest $request): WebhookResponse
            {
                $this->calls++;

                return new WebhookResponse($this->calls === 3 ? 200 : 500, 1);
            }
        });
        $deliveryId = $this->createPendingDelivery();

        self::assertSame(1, app(PublishDeliveryOutbox::class)->handle(100)->published);
        self::assertSame(1, $this->queueMessageCount());
        self::assertSame(0, Artisan::call('deliveries:consume-rabbitmq', ['--once' => true]));
        $firstDue = new DateTimeImmutable('2026-09-10T12:00:10+00:00');
        $this->assertDatabaseHas('deliveries', [
            'public_id' => $deliveryId,
            'status' => 'retry_scheduled',
            'next_attempt_at' => $firstDue,
        ]);

        $clock->set(new DateTimeImmutable('2026-09-10T12:00:09+00:00'));
        self::assertSame(0, app(PublishDeliveryOutbox::class)->handle(100)->published);
        self::assertSame(0, $this->queueMessageCount());
        $clock->set($firstDue);
        self::assertSame(1, app(PublishDeliveryOutbox::class)->handle(100)->published);
        self::assertSame(1, $this->queueMessageCount());
        self::assertSame(0, Artisan::call('deliveries:consume-rabbitmq', ['--once' => true]));

        $secondDue = new DateTimeImmutable('2026-09-10T12:01:10+00:00');
        $clock->set(new DateTimeImmutable('2026-09-10T12:01:09+00:00'));
        self::assertSame(0, app(PublishDeliveryOutbox::class)->handle(100)->published);
        self::assertSame(0, $this->queueMessageCount());
        $clock->set($secondDue);
        self::assertSame(1, app(PublishDeliveryOutbox::class)->handle(100)->published);
        self::assertSame(1, $this->queueMessageCount());
        self::assertSame(0, Artisan::call('deliveries:consume-rabbitmq', ['--once' => true]));

        $this->assertDatabaseHas('deliveries', ['public_id' => $deliveryId, 'status' => 'succeeded', 'next_attempt_at' => null]);
        $this->assertDatabaseHas('delivery_attempts', ['attempt_number' => 1, 'status' => 'failed', 'response_status' => 500]);
        $this->assertDatabaseHas('delivery_attempts', ['attempt_number' => 2, 'status' => 'failed', 'response_status' => 500]);
        $this->assertDatabaseHas('delivery_attempts', ['attempt_number' => 3, 'status' => 'succeeded', 'response_status' => 200]);
        $this->assertDatabaseCount('delivery_attempts', 3);
    }

    public function test_invalid_transport_configuration_fails_when_the_transport_is_resolved(): void
    {
        config(['delivery.transport' => 'unsupported']);
        $this->app->forgetInstance(DeliveryTransport::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('DELIVERY_TRANSPORT must be redis or rabbitmq.');
        app(DeliveryTransport::class);
    }

    public function test_real_connection_loss_before_confirm_is_known_transport_unavailable(): void
    {
        $configuration = new RabbitMqConfiguration(
            '127.0.0.1',
            1,
            'eventrelay',
            'not-logged',
            '/',
            'eventrelay.delivery',
            'eventrelay.deliveries',
            'delivery.process',
            1,
            0.1,
        );
        $transport = new RabbitMqDeliveryTransport(
            new PhpAmqpLibRabbitMqDeliveryPublisher($configuration),
            $configuration,
        );

        $this->expectException(DeliveryTransportUnavailable::class);
        $transport->publish(DeliveryId::fromString('9db4d301-f44a-4dab-a545-6f9046cbeb6f'));
    }

    public function test_two_mysql_publishers_claim_disjoint_outbox_rows_and_physically_publish_each_rabbit_message_once(): void
    {
        $this->requireMySqlAndRabbitMq();
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('This integration test requires MySQL/InnoDB and pcntl.');
        }

        $this->purgeQueue();
        $this->useRabbitTransport();
        $this->createPendingDelivery();
        $this->createPendingDelivery();
        $firstPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $secondPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($firstPair);
        self::assertNotFalse($secondPair);
        [$firstParent, $firstChild] = $firstPair;
        [$secondParent, $secondChild] = $secondPair;

        $firstPid = pcntl_fork();
        if ($firstPid === -1) {
            self::fail('Unable to fork the first RabbitMQ outbox publisher.');
        }
        if ($firstPid === 0) {
            fclose($firstParent);
            $this->runRabbitPublisher($firstChild);
        }

        $secondPid = pcntl_fork();
        if ($secondPid === -1) {
            self::fail('Unable to fork the second RabbitMQ outbox publisher.');
        }
        if ($secondPid === 0) {
            fclose($secondParent);
            $this->runRabbitPublisher($secondChild);
        }

        fclose($firstChild);
        fclose($secondChild);
        stream_set_timeout($firstParent, 10);
        stream_set_timeout($secondParent, 10);

        try {
            fwrite($firstParent, "start\n");
            fwrite($secondParent, "start\n");
            self::assertSame("published:1\n", fgets($firstParent));
            self::assertSame("published:1\n", fgets($secondParent));
            pcntl_waitpid($firstPid, $firstStatus);
            pcntl_waitpid($secondPid, $secondStatus);
            self::assertSame(0, pcntl_wexitstatus($firstStatus));
            self::assertSame(0, pcntl_wexitstatus($secondStatus));

            $this->reconnectAfterFork();
            self::assertSame(2, $this->queueMessageCount());
            self::assertSame(2, DB::table('delivery_outbox_messages')->where('status', 'published')->count());
            self::assertSame(2, DB::table('delivery_outbox_messages')->where('publication_attempts', 1)->count());
        } finally {
            fclose($firstParent);
            fclose($secondParent);
        }
    }

    public function test_confirmed_rabbit_publication_replays_after_a_lease_expires_before_mark_published_and_the_duplicate_is_absorbed_by_the_delivery_claim(): void
    {
        $this->requireMySqlAndRabbitMq();
        $this->purgeQueue();
        $this->useRabbitTransport();
        $clock = new FrozenClock(new DateTimeImmutable('2026-09-10T12:00:00+00:00'));
        $this->app->instance(Clock::class, $clock);
        $sent = 0;
        $this->app->instance(WebhookTargetResolver::class, new class implements WebhookTargetResolver
        {
            public function resolve(string $url): WebhookTarget
            {
                return new WebhookTarget($url, 'receiver.example', 443, '1.1.1.1');
            }
        });
        $this->app->instance(WebhookTransport::class, new class($sent) implements WebhookTransport
        {
            public function __construct(private int &$sent) {}

            public function send(WebhookTarget $target, WebhookRequest $request): WebhookResponse
            {
                $this->sent++;

                return new WebhookResponse(204, 1);
            }
        });
        $deliveryId = $this->createPendingDelivery();
        $claim = app(DeliveryOutboxPublisherRepository::class)->claim(
            1,
            $clock->now(),
            $clock->now()->add(new DateInterval('PT60S')),
        );
        self::assertCount(1, $claim);

        app(DeliveryTransport::class)->publish($claim[0]->intent->deliveryId);
        $clock->set(new DateTimeImmutable('2026-09-10T12:01:00+00:00'));
        self::assertSame(1, app(PublishDeliveryOutbox::class)->handle(100)->published);
        self::assertSame(2, $this->queueMessageCount());

        self::assertSame(0, Artisan::call('deliveries:consume-rabbitmq', ['--once' => true]));
        self::assertSame(0, Artisan::call('deliveries:consume-rabbitmq', ['--once' => true]));
        self::assertSame(1, $sent);
        self::assertSame(1, DB::table('delivery_attempts')->count());
        $this->assertDatabaseHas('deliveries', ['public_id' => $deliveryId, 'status' => 'succeeded']);
        $this->assertDatabaseHas('delivery_outbox_messages', [
            'dedupe_key' => "delivery:{$deliveryId}:attempt:1",
            'status' => 'published',
            'publication_attempts' => 2,
        ]);
    }

    public function test_mandatory_unroutable_publication_triggers_a_broker_return_before_any_outbox_message_can_be_marked_published(): void
    {
        $this->requireMySqlAndRabbitMq();
        $connection = $this->connection();
        $channel = $connection->channel();
        $returned = false;

        try {
            $channel->set_return_listener(static function () use (&$returned): void {
                $returned = true;
            });
            $channel->confirm_select();
            $channel->basic_publish(
                new AMQPMessage('{"v":1,"type":"delivery.process","delivery_id":"9db4d301-f44a-4dab-a545-6f9046cbeb6f"}', [
                    'content_type' => 'application/json',
                    'delivery_mode' => 2,
                ]),
                'amq.direct',
                'eventrelay.unroutable.'.bin2hex(random_bytes(8)),
                true,
            );
            $channel->wait_for_pending_acks_returns($this->configuration()->timeout);
            self::assertTrue($returned);
        } finally {
            $channel->close();
            $connection->close();
        }
    }

    public function test_transport_switches_publish_only_to_the_selected_broker_without_dual_write(): void
    {
        $this->requireMySqlAndRabbitMq();
        $this->purgeQueue();
        Redis::connection()->del('queues:deliveries', 'queues:deliveries:delayed', 'queues:deliveries:reserved');
        $this->useRabbitTransport();
        $this->createPendingDelivery();

        self::assertSame(1, app(PublishDeliveryOutbox::class)->handle(100)->published);
        self::assertSame(1, $this->queueMessageCount());
        self::assertSame(0, Redis::connection()->lLen('queues:deliveries'));

        $this->createPendingDelivery();
        config(['delivery.transport' => 'redis']);
        $this->app->forgetInstance(DeliveryTransport::class);
        self::assertSame(2, app(PublishDeliveryOutbox::class)->handle(100)->published);
        self::assertSame(1, $this->queueMessageCount());
        self::assertSame(2, Redis::connection()->lLen('queues:deliveries'));
    }

    public function test_consumer_crash_after_the_delivery_claim_redelivers_without_creating_a_second_attempt_and_stale_recovery_remains_authoritative(): void
    {
        $this->requireMySqlAndRabbitMq();
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('This integration test requires pcntl and posix process control.');
        }

        $this->purgeQueue();
        $this->useRabbitTransport();
        $deliveryId = $this->createPendingDelivery();
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$parent, $child] = $pair;
        app(DeliveryTransport::class)->publish(DeliveryId::fromString($deliveryId));

        $pid = pcntl_fork();
        if ($pid === -1) {
            self::fail('Unable to fork the RabbitMQ consumer crash process.');
        }
        if ($pid === 0) {
            fclose($parent);
            $this->runConsumerUntilTransportBarrier($child);
        }

        fclose($child);
        stream_set_timeout($parent, 10);

        try {
            self::assertSame("entered-transport\n", fgets($parent));
            self::assertTrue(posix_kill($pid, SIGKILL));
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifsignaled($status));

            $this->reconnectAfterFork();
            self::assertTrue($this->waitForRedelivery());
            $this->assertDatabaseHas('deliveries', ['public_id' => $deliveryId, 'status' => 'processing']);
            $this->assertDatabaseHas('delivery_attempts', ['attempt_number' => 1, 'status' => 'started']);
            self::assertSame(1, DB::table('delivery_attempts')->count());

            self::assertSame(0, Artisan::call('deliveries:consume-rabbitmq', ['--once' => true]));
            self::assertSame(0, $this->queueMessageCount());
            self::assertSame(1, DB::table('delivery_attempts')->count());
            $this->assertDatabaseHas('delivery_attempts', ['attempt_number' => 1, 'status' => 'started']);

            $startedAt = new DateTimeImmutable((string) DB::table('delivery_attempts')->value('started_at'));
            $this->app->instance(Clock::class, new FrozenClock($startedAt->modify('+60 seconds')));
            self::assertNotNull(app(RecoverStaleDelivery::class)->handle(DeliveryId::fromString($deliveryId)));
            $this->assertDatabaseHas('deliveries', ['public_id' => $deliveryId, 'status' => 'retry_scheduled']);
            $this->assertDatabaseHas('delivery_attempts', [
                'attempt_number' => 1,
                'status' => 'abandoned',
                'failure_type' => 'stale_processing',
            ]);
            $this->assertDatabaseHas('delivery_outbox_messages', [
                'dedupe_key' => "delivery:{$deliveryId}:attempt:2",
                'attempt_number' => 2,
                'status' => 'pending',
            ]);
        } finally {
            fclose($parent);
        }
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

    private function getOneMessage(): ?AMQPMessage
    {
        $connection = $this->connection();
        $channel = $connection->channel();
        (new RabbitMqTopology($this->configuration()))->declare($channel);
        $message = $channel->basic_get($this->configuration()->queue, false);
        if (! $message instanceof AMQPMessage) {
            $channel->close();
            $connection->close();

            return null;
        }

        return $message;
    }

    private function publishRaw(string $body): void
    {
        $connection = $this->connection();
        $channel = $connection->channel();
        (new RabbitMqTopology($this->configuration()))->declare($channel);
        $channel->basic_publish(
            new AMQPMessage($body, ['delivery_mode' => 2, 'content_type' => 'application/json']),
            $this->configuration()->exchange,
            $this->configuration()->routingKey,
        );
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

    /** @return never-return */
    private function runConsumerUntilTransportBarrier(mixed $socket): void
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
            $this->app->instance(WebhookTransport::class, new class($socket) implements WebhookTransport
            {
                public function __construct(private readonly mixed $socket) {}

                public function send(WebhookTarget $target, WebhookRequest $request): WebhookResponse
                {
                    fwrite($this->socket, "entered-transport\n");

                    while (true) {
                        usleep(100_000);
                    }
                }
            });
            app(RabbitMqDeliveryConsumer::class)->consumeOnce(1);
            fclose($socket);
            exit(1);
        } catch (\Throwable $exception) {
            fwrite($socket, 'error:'.$exception::class."\n");
            fclose($socket);
            exit(1);
        }
    }

    /** @return never-return */
    private function runRabbitPublisher(mixed $socket): void
    {
        try {
            $this->reconnectAfterFork();
            config(['delivery.transport' => 'rabbitmq']);
            $this->app->forgetInstance(DeliveryTransport::class);
            if (fgets($socket) !== "start\n") {
                throw new \LogicException('RabbitMQ publisher did not receive the test barrier release.');
            }

            $result = app(PublishDeliveryOutbox::class)->handle(1);
            fwrite($socket, "published:{$result->published}\n");
            fclose($socket);
            exit(0);
        } catch (\Throwable $exception) {
            fwrite($socket, 'error:'.$exception::class."\n");
            fclose($socket);
            exit(1);
        }
    }

    private function reconnectAfterFork(): void
    {
        DB::disconnect();
        DB::purge();
        DB::connection()->getPdo();
    }

    private function waitForRedelivery(): bool
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            if ($this->queueMessageCount() > 0) {
                return true;
            }

            usleep(100_000);
        }

        return false;
    }

    private function requireMySqlAndRabbitMq(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('This integration test requires MySQL/InnoDB and RabbitMQ.');
        }

        try {
            $connection = $this->connection();
            $connection->close();
        } catch (\Throwable) {
            $this->markTestSkipped('This integration test requires an available RabbitMQ service.');
        }
    }

    private function createEndpoint(string $name): string
    {
        return (string) $this->postJson('/api/endpoints', [
            'name' => $name,
            'url' => 'https://receiver.example/rabbit',
        ])->assertCreated()->json('data.id');
    }

    private function replaceSubscriptions(string $endpointId, array $types): void
    {
        $this->putJson("/api/endpoints/{$endpointId}/subscriptions", ['types' => $types])->assertOk();
    }

    private function createPendingDelivery(): string
    {
        $endpointId = $this->createEndpoint('Future Rabbit endpoint');
        $this->replaceSubscriptions($endpointId, ['order.paid']);
        $eventId = (string) $this->postJson('/api/events', [
            'type' => 'order.paid', 'payload' => (object) [],
        ])->assertCreated()->json('data.id');

        return (string) DB::table('deliveries')
            ->join('events', 'deliveries.event_id', '=', 'events.id')
            ->where('events.public_id', $eventId)
            ->value('deliveries.public_id');
    }
}

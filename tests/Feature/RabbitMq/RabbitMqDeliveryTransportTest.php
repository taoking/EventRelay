<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMq;

use App\Application\Delivery\DeliveryTransport;
use App\Application\Delivery\DeliveryTransportUnavailable;
use App\Application\Delivery\PublishDeliveryOutbox;
use App\Application\Delivery\WebhookRequest;
use App\Application\Delivery\WebhookResponse;
use App\Application\Delivery\WebhookTarget;
use App\Application\Delivery\WebhookTargetResolver;
use App\Application\Delivery\WebhookTransport;
use App\Domain\Delivery\DeliveryId;
use App\Infrastructure\RabbitMq\PhpAmqpLibRabbitMqDeliveryPublisher;
use App\Infrastructure\RabbitMq\RabbitMqConfiguration;
use App\Infrastructure\RabbitMq\RabbitMqDeliveryTransport;
use App\Infrastructure\RabbitMq\RabbitMqTopology;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
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

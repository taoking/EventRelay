<?php

declare(strict_types=1);

namespace App\Infrastructure\RabbitMq;

use App\Application\Delivery\ProcessPendingDelivery;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

final readonly class PhpAmqpLibRabbitMqDeliveryConsumer implements RabbitMqDeliveryConsumer
{
    public function __construct(
        private RabbitMqConfiguration $configuration,
        private ProcessPendingDelivery $processor,
    ) {}

    public function consumeOnce(int $prefetch): bool
    {
        [$connection, $channel] = $this->open($prefetch);

        try {
            $message = $channel->basic_get($this->configuration->queue, false);
            if (! $message instanceof AMQPMessage) {
                return false;
            }

            $this->handleMessage($message);

            return true;
        } finally {
            $channel->close();
            $connection->close();
        }
    }

    public function consume(int $prefetch, callable $shouldStop): void
    {
        [$connection, $channel] = $this->open($prefetch);

        try {
            $channel->basic_consume(
                $this->configuration->queue,
                '',
                false,
                false,
                false,
                false,
                function (AMQPMessage $message): void {
                    $this->handleMessage($message);
                },
            );

            while ($channel->is_consuming() && ! $shouldStop()) {
                $channel->wait(null, false, 1);
            }
        } finally {
            $channel->close();
            $connection->close();
        }
    }

    /** @return array{AMQPStreamConnection, AMQPChannel} */
    private function open(int $prefetch): array
    {
        $connection = new AMQPStreamConnection(
            $this->configuration->host,
            $this->configuration->port,
            $this->configuration->user,
            $this->configuration->password,
            $this->configuration->vhost,
            false,
            'AMQPLAIN',
            null,
            'en_US',
            $this->configuration->timeout,
            $this->configuration->timeout,
        );
        $channel = $connection->channel();
        (new RabbitMqTopology($this->configuration))->declare($channel);
        $channel->basic_qos(0, $prefetch, false);

        return [$connection, $channel];
    }

    private function handleMessage(AMQPMessage $message): void
    {
        try {
            $envelope = RabbitMqDeliveryEnvelope::fromBody($message->getBody());
        } catch (InvalidRabbitMqDeliveryEnvelope $exception) {
            Log::warning('Rejected malformed RabbitMQ delivery envelope.', [
                'transport' => 'rabbitmq',
                'exchange' => $this->configuration->exchange,
                'queue' => $this->configuration->queue,
                'routing_key' => $this->configuration->routingKey,
                'exception' => $exception::class,
            ]);
            $message->reject(false);

            return;
        }

        $this->processor->handle($envelope->deliveryId);
        $message->ack();
    }
}

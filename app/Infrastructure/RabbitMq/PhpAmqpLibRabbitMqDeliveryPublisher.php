<?php

declare(strict_types=1);

namespace App\Infrastructure\RabbitMq;

use App\Domain\Delivery\DeliveryId;
use JsonException;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPChannelClosedException;
use PhpAmqpLib\Exception\AMQPConnectionBlockedException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPHeartbeatMissedException;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Exception\AMQPSocketException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

final readonly class PhpAmqpLibRabbitMqDeliveryPublisher implements RabbitMqDeliveryPublisher
{
    public function __construct(private RabbitMqConfiguration $configuration) {}

    public function publish(DeliveryId $deliveryId): void
    {
        $connection = null;
        $channel = null;

        try {
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
            $this->publishConfirmed($channel, $deliveryId);
        } catch (AMQPChannelClosedException|AMQPConnectionBlockedException|AMQPConnectionClosedException|AMQPHeartbeatMissedException|AMQPIOException|AMQPProtocolChannelException|AMQPSocketException|AMQPTimeoutException $exception) {
            throw new RabbitMqPublicationUnavailable('RabbitMQ publication is unavailable.', 0, $exception);
        }

        $channel->close();
        $connection->close();
    }

    private function publishConfirmed(AMQPChannel $channel, DeliveryId $deliveryId): void
    {
        $returned = false;
        $nacked = false;

        $channel->set_return_listener(static function () use (&$returned): void {
            $returned = true;
        });
        $channel->set_nack_handler(static function () use (&$nacked): void {
            $nacked = true;
        });
        $channel->confirm_select();
        $channel->basic_publish(
            new AMQPMessage($this->envelope($deliveryId), [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'type' => 'delivery.process',
                'message_id' => $deliveryId->toString(),
            ]),
            $this->configuration->exchange,
            $this->configuration->routingKey,
            true,
        );
        $channel->wait_for_pending_acks_returns($this->configuration->timeout);

        if ($returned || $nacked) {
            throw new RabbitMqPublicationUnavailable('RabbitMQ did not accept the delivery publication.');
        }
    }

    private function envelope(DeliveryId $deliveryId): string
    {
        try {
            return json_encode([
                'v' => 1,
                'type' => 'delivery.process',
                'delivery_id' => $deliveryId->toString(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new \LogicException('The RabbitMQ delivery envelope could not be encoded.', 0, $exception);
        }
    }
}

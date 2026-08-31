<?php

declare(strict_types=1);

namespace App\Infrastructure\RabbitMq;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Wire\AMQPTable;

final readonly class RabbitMqTopology
{
    public function __construct(private RabbitMqConfiguration $configuration) {}

    public function declare(AMQPChannel $channel): void
    {
        $channel->exchange_declare(
            $this->configuration->exchange,
            AMQPExchangeType::DIRECT,
            false,
            true,
            false,
        );
        $channel->queue_declare(
            $this->configuration->queue,
            false,
            true,
            false,
            false,
            false,
            new AMQPTable(['x-queue-type' => 'quorum']),
        );
        $channel->queue_bind(
            $this->configuration->queue,
            $this->configuration->exchange,
            $this->configuration->routingKey,
        );
    }
}

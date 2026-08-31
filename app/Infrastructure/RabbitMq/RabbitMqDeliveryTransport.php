<?php

declare(strict_types=1);

namespace App\Infrastructure\RabbitMq;

use App\Application\Delivery\DeliveryTransport;
use App\Application\Delivery\DeliveryTransportUnavailable;
use App\Domain\Delivery\DeliveryId;
use Illuminate\Support\Facades\Log;

final readonly class RabbitMqDeliveryTransport implements DeliveryTransport
{
    public function __construct(
        private RabbitMqDeliveryPublisher $publisher,
        private RabbitMqConfiguration $configuration,
    ) {}

    public function publish(DeliveryId $deliveryId): void
    {
        try {
            $this->publisher->publish($deliveryId);
        } catch (RabbitMqPublicationUnavailable $exception) {
            Log::warning('Delivery transport publication failed.', [
                'delivery_id' => $deliveryId->toString(),
                'transport' => 'rabbitmq',
                'exchange' => $this->configuration->exchange,
                'queue' => $this->configuration->queue,
                'routing_key' => $this->configuration->routingKey,
                'exception' => $exception::class,
            ]);

            throw new DeliveryTransportUnavailable($deliveryId, 'rabbitmq', $exception);
        }
    }
}

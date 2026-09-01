<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers;

use App\Application\Delivery\DeliveryTransport;
use App\Infrastructure\Console\OutboxWorkerSleeper;
use App\Infrastructure\Console\SystemOutboxWorkerSleeper;
use App\Infrastructure\Queue\LaravelRedisDeliveryTransport;
use App\Infrastructure\RabbitMq\PhpAmqpLibRabbitMqDeliveryConsumer;
use App\Infrastructure\RabbitMq\PhpAmqpLibRabbitMqDeliveryPublisher;
use App\Infrastructure\RabbitMq\RabbitMqConfiguration;
use App\Infrastructure\RabbitMq\RabbitMqDeliveryConsumer;
use App\Infrastructure\RabbitMq\RabbitMqDeliveryPublisher;
use App\Infrastructure\RabbitMq\RabbitMqDeliveryTransport;
use Illuminate\Support\ServiceProvider;
use LogicException;

final class DeliveryTransportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OutboxWorkerSleeper::class, SystemOutboxWorkerSleeper::class);
        $this->app->singleton(RabbitMqConfiguration::class, static fn (): RabbitMqConfiguration => RabbitMqConfiguration::fromConfig(config('delivery.rabbitmq')));
        $this->app->bind(RabbitMqDeliveryPublisher::class, PhpAmqpLibRabbitMqDeliveryPublisher::class);
        $this->app->bind(RabbitMqDeliveryConsumer::class, PhpAmqpLibRabbitMqDeliveryConsumer::class);

        $this->app->bind(DeliveryTransport::class, function (): DeliveryTransport {
            return match (config('delivery.transport')) {
                'redis' => app(LaravelRedisDeliveryTransport::class),
                'rabbitmq' => app(RabbitMqDeliveryTransport::class),
                default => throw new LogicException('DELIVERY_TRANSPORT must be redis or rabbitmq.'),
            };
        });
    }
}

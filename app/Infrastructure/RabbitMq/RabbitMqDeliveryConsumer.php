<?php

declare(strict_types=1);

namespace App\Infrastructure\RabbitMq;

interface RabbitMqDeliveryConsumer
{
    public function consumeOnce(int $prefetch): bool;

    public function consume(int $prefetch, callable $shouldStop): void;
}

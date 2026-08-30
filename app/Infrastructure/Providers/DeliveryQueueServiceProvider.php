<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers;

use App\Application\Delivery\DeliveryQueue;
use App\Infrastructure\Queue\LaravelRedisDeliveryQueue;
use Illuminate\Support\ServiceProvider;

final class DeliveryQueueServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DeliveryQueue::class, LaravelRedisDeliveryQueue::class);
    }
}

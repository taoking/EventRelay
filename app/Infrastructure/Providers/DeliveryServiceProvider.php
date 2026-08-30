<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers;

use App\Application\Delivery\DeliveryExecutionRepository;
use App\Application\Delivery\DeliveryRepository;
use App\Application\Delivery\PendingDeliveryFinder;
use App\Infrastructure\Persistence\Eloquent\EloquentDeliveryExecutionRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentDeliveryRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentPendingDeliveryFinder;
use Illuminate\Support\ServiceProvider;

final class DeliveryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DeliveryRepository::class, EloquentDeliveryRepository::class);
        $this->app->bind(DeliveryExecutionRepository::class, EloquentDeliveryExecutionRepository::class);
        $this->app->bind(PendingDeliveryFinder::class, EloquentPendingDeliveryFinder::class);
    }
}

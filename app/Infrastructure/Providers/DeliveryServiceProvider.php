<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers;

use App\Application\Clock\Clock;
use App\Application\Delivery\DeliveryExecutionRepository;
use App\Application\Delivery\DeliveryRepository;
use App\Application\Delivery\DueRetryFinder;
use App\Application\Delivery\PendingDeliveryFinder;
use App\Application\Delivery\StaleDeliveryFinder;
use App\Infrastructure\Clock\SystemClock;
use App\Infrastructure\Persistence\Eloquent\EloquentDeliveryExecutionRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentDeliveryRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentDueRetryFinder;
use App\Infrastructure\Persistence\Eloquent\EloquentPendingDeliveryFinder;
use App\Infrastructure\Persistence\Eloquent\EloquentStaleDeliveryFinder;
use Illuminate\Support\ServiceProvider;

final class DeliveryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->bind(DeliveryRepository::class, EloquentDeliveryRepository::class);
        $this->app->bind(DeliveryExecutionRepository::class, EloquentDeliveryExecutionRepository::class);
        $this->app->bind(PendingDeliveryFinder::class, EloquentPendingDeliveryFinder::class);
        $this->app->bind(DueRetryFinder::class, EloquentDueRetryFinder::class);
        $this->app->bind(StaleDeliveryFinder::class, EloquentStaleDeliveryFinder::class);
    }
}

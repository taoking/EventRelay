<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers;

use App\Application\Delivery\DeliveryRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentDeliveryRepository;
use Illuminate\Support\ServiceProvider;

final class DeliveryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DeliveryRepository::class, EloquentDeliveryRepository::class);
    }
}

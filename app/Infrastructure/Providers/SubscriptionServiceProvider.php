<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers;

use App\Application\Subscription\EndpointSubscriptionRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentEndpointSubscriptionRepository;
use Illuminate\Support\ServiceProvider;

final class SubscriptionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EndpointSubscriptionRepository::class, EloquentEndpointSubscriptionRepository::class);
    }
}

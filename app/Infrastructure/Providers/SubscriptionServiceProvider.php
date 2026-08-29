<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers;

use App\Application\Subscription\EndpointSubscriptionRepository;
use App\Application\Subscription\SubscriptionMatcher;
use App\Infrastructure\Persistence\Eloquent\EloquentEndpointSubscriptionRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentSubscriptionMatcher;
use Illuminate\Support\ServiceProvider;

final class SubscriptionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EndpointSubscriptionRepository::class, EloquentEndpointSubscriptionRepository::class);
        $this->app->bind(SubscriptionMatcher::class, EloquentSubscriptionMatcher::class);
    }
}

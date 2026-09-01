<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers;

use App\Application\Endpoint\EndpointPageRepository;
use App\Application\Endpoint\EndpointRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentEndpointPageRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentEndpointRepository;
use Illuminate\Support\ServiceProvider;

final class EndpointServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EndpointRepository::class, EloquentEndpointRepository::class);
        $this->app->bind(EndpointPageRepository::class, EloquentEndpointPageRepository::class);
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers;

use App\Application\Operations\OperationalMetricsRenderer;
use App\Application\Operations\OperationalReadinessRepository;
use App\Application\Operations\OperationalSnapshotRepository;
use App\Application\Operations\OperationsEndpointAccess;
use App\Infrastructure\Operations\OperationsEndpointConfiguration;
use App\Infrastructure\Operations\PrometheusOperationalMetricsRenderer;
use App\Infrastructure\Persistence\Eloquent\EloquentOperationalReadModel;
use Illuminate\Support\ServiceProvider;

final class OperationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OperationsEndpointConfiguration::class, static fn (): OperationsEndpointConfiguration => OperationsEndpointConfiguration::fromConfig(config('operations')));
        $this->app->bind(OperationsEndpointAccess::class, OperationsEndpointConfiguration::class);
        $this->app->bind(OperationalReadinessRepository::class, EloquentOperationalReadModel::class);
        $this->app->bind(OperationalSnapshotRepository::class, EloquentOperationalReadModel::class);
        $this->app->singleton(PrometheusOperationalMetricsRenderer::class);
        $this->app->bind(OperationalMetricsRenderer::class, PrometheusOperationalMetricsRenderer::class);
    }

    public function boot(): void
    {
        $this->app->make(OperationsEndpointConfiguration::class);
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers;

use App\Application\Event\EventRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentEventRepository;
use Illuminate\Support\ServiceProvider;

final class EventServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EventRepository::class, EloquentEventRepository::class);
    }
}

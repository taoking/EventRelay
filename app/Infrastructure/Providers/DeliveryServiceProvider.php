<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers;

use App\Application\Clock\Clock;
use App\Application\CoreList\CoreListCursorCodec;
use App\Application\DeadLetter\DeadLetterCursorCodec;
use App\Application\DeadLetter\DeadLetterQueryRepository;
use App\Application\Delivery\DeliveryExecutionRepository;
use App\Application\Delivery\DeliveryOutboxIntentFinder;
use App\Application\Delivery\DeliveryOutboxPublisherRepository;
use App\Application\Delivery\DeliveryOutboxRecovery;
use App\Application\Delivery\DeliveryOutboxWriter;
use App\Application\Delivery\DeliveryPageRepository;
use App\Application\Delivery\DeliveryReplayCreator;
use App\Application\Delivery\DeliveryRepository;
use App\Application\Delivery\DeliverySnapshotCreator;
use App\Application\Delivery\DueRetryFinder;
use App\Application\Delivery\PendingDeliveryFinder;
use App\Application\Delivery\StaleDeliveryFinder;
use App\Infrastructure\Clock\SystemClock;
use App\Infrastructure\CoreList\LaravelCoreListCursorCodec;
use App\Infrastructure\DeadLetter\LaravelDeadLetterCursorCodec;
use App\Infrastructure\Persistence\Eloquent\EloquentDeadLetterQueryRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentDeliveryExecutionRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentDeliveryOutboxIntentFinder;
use App\Infrastructure\Persistence\Eloquent\EloquentDeliveryOutboxPublisherRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentDeliveryOutboxRecovery;
use App\Infrastructure\Persistence\Eloquent\EloquentDeliveryOutboxWriter;
use App\Infrastructure\Persistence\Eloquent\EloquentDeliveryPageRepository;
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
        $this->app->bind(CoreListCursorCodec::class, LaravelCoreListCursorCodec::class);
        $this->app->bind(DeadLetterCursorCodec::class, LaravelDeadLetterCursorCodec::class);
        $this->app->bind(DeadLetterQueryRepository::class, EloquentDeadLetterQueryRepository::class);
        $this->app->bind(DeliveryRepository::class, EloquentDeliveryRepository::class);
        $this->app->bind(DeliveryPageRepository::class, EloquentDeliveryPageRepository::class);
        $this->app->bind(DeliveryReplayCreator::class, EloquentDeliveryRepository::class);
        $this->app->bind(DeliverySnapshotCreator::class, EloquentDeliveryRepository::class);
        $this->app->bind(DeliveryExecutionRepository::class, EloquentDeliveryExecutionRepository::class);
        $this->app->bind(DeliveryOutboxWriter::class, EloquentDeliveryOutboxWriter::class);
        $this->app->bind(DeliveryOutboxRecovery::class, EloquentDeliveryOutboxRecovery::class);
        $this->app->bind(DeliveryOutboxPublisherRepository::class, EloquentDeliveryOutboxPublisherRepository::class);
        $this->app->bind(DeliveryOutboxIntentFinder::class, EloquentDeliveryOutboxIntentFinder::class);
        $this->app->bind(PendingDeliveryFinder::class, EloquentPendingDeliveryFinder::class);
        $this->app->bind(DueRetryFinder::class, EloquentDueRetryFinder::class);
        $this->app->bind(StaleDeliveryFinder::class, EloquentStaleDeliveryFinder::class);
    }
}

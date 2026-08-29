<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers;

use App\Application\Transaction\TransactionManager;
use App\Infrastructure\Transaction\EloquentTransactionManager;
use Illuminate\Support\ServiceProvider;

final class TransactionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TransactionManager::class, EloquentTransactionManager::class);
    }
}

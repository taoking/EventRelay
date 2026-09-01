<?php

declare(strict_types=1);

use App\Http\Controllers\Internal\OperationsController;
use App\Http\Middleware\EnsureOperationsAccess;
use Illuminate\Support\Facades\Route;

Route::middleware(EnsureOperationsAccess::class)->prefix('internal')->group(function (): void {
    Route::get('/health/live', [OperationsController::class, 'live']);
    Route::get('/health/ready', [OperationsController::class, 'ready']);
    Route::get('/metrics', [OperationsController::class, 'metrics']);
});

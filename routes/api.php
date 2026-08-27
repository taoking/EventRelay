<?php

declare(strict_types=1);

use App\Http\Controllers\Api\EndpointController;
use Illuminate\Support\Facades\Route;

Route::get('/endpoints', [EndpointController::class, 'index']);
Route::post('/endpoints', [EndpointController::class, 'store']);
Route::get('/endpoints/{id}', [EndpointController::class, 'show']);
Route::patch('/endpoints/{id}', [EndpointController::class, 'update']);
Route::delete('/endpoints/{id}', [EndpointController::class, 'destroy']);

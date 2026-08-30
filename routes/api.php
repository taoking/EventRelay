<?php

declare(strict_types=1);

use App\Http\Controllers\Api\DeliveryController;
use App\Http\Controllers\Api\EndpointController;
use App\Http\Controllers\Api\EndpointSubscriptionController;
use App\Http\Controllers\Api\EventController;
use Illuminate\Support\Facades\Route;

Route::get('/events', [EventController::class, 'index']);
Route::post('/events', [EventController::class, 'store']);
Route::get('/events/{id}', [EventController::class, 'show']);

Route::get('/deliveries', [DeliveryController::class, 'index']);
Route::get('/deliveries/{id}/attempts', [DeliveryController::class, 'attempts']);
Route::get('/deliveries/{id}', [DeliveryController::class, 'show']);

Route::get('/endpoints', [EndpointController::class, 'index']);
Route::post('/endpoints', [EndpointController::class, 'store']);
Route::get('/endpoints/{id}', [EndpointController::class, 'show']);
Route::patch('/endpoints/{id}', [EndpointController::class, 'update']);
Route::delete('/endpoints/{id}', [EndpointController::class, 'destroy']);
Route::get('/endpoints/{id}/subscriptions', [EndpointSubscriptionController::class, 'index']);
Route::put('/endpoints/{id}/subscriptions', [EndpointSubscriptionController::class, 'replace']);

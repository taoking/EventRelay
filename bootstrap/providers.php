<?php

use App\Infrastructure\Providers\DeliveryServiceProvider;
use App\Infrastructure\Providers\EndpointServiceProvider;
use App\Infrastructure\Providers\EventServiceProvider;
use App\Infrastructure\Providers\SubscriptionServiceProvider;
use App\Infrastructure\Providers\TransactionServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    DeliveryServiceProvider::class,
    EventServiceProvider::class,
    EndpointServiceProvider::class,
    SubscriptionServiceProvider::class,
    TransactionServiceProvider::class,
];

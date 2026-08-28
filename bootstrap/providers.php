<?php

use App\Infrastructure\Providers\EndpointServiceProvider;
use App\Infrastructure\Providers\EventServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    EventServiceProvider::class,
    EndpointServiceProvider::class,
];

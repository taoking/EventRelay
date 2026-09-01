<?php

return [
    'transport' => env('DELIVERY_TRANSPORT', 'redis'),
    'rabbitmq' => [
        'host' => env('RABBITMQ_HOST', '127.0.0.1'),
        'port' => (int) env('RABBITMQ_PORT', 5672),
        'user' => env('RABBITMQ_USER', 'guest'),
        'password' => env('RABBITMQ_PASSWORD', 'guest'),
        'vhost' => env('RABBITMQ_VHOST', '/'),
        'exchange' => env('RABBITMQ_EXCHANGE', 'eventrelay.delivery'),
        'queue' => env('RABBITMQ_QUEUE', 'eventrelay.deliveries'),
        'routing_key' => env('RABBITMQ_ROUTING_KEY', 'delivery.process'),
        'prefetch' => (int) env('RABBITMQ_PREFETCH', 10),
        'timeout' => (float) env('RABBITMQ_TIMEOUT', 5),
    ],
];

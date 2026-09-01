<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('OPERATIONS_ENDPOINTS_ENABLED', false),
    'bearer_token' => env('OPERATIONS_BEARER_TOKEN', ''),
];

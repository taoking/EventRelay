<?php

declare(strict_types=1);

namespace App\Domain\DeliveryAttempt;

enum DeliveryFailureType: string
{
    case HttpStatus = 'http_status';
    case Timeout = 'timeout';
    case NetworkError = 'network_error';
    case UnsafeTarget = 'unsafe_target';
}

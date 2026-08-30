<?php

declare(strict_types=1);

namespace App\Domain\DeliveryAttempt;

enum DeliveryAttemptStatus: string
{
    case Started = 'started';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Abandoned = 'abandoned';
}

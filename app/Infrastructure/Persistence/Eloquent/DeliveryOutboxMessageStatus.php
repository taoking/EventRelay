<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

enum DeliveryOutboxMessageStatus: string
{
    case Pending = 'pending';
    case Publishing = 'publishing';
    case Published = 'published';
}

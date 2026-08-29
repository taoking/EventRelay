<?php

declare(strict_types=1);

namespace App\Domain\Delivery;

enum DeliveryStatus: string
{
    case Pending = 'pending';
}

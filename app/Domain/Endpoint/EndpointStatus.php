<?php

declare(strict_types=1);

namespace App\Domain\Endpoint;

enum EndpointStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
}

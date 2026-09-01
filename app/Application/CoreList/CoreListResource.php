<?php

declare(strict_types=1);

namespace App\Application\CoreList;

enum CoreListResource: string
{
    case Events = 'events';
    case Deliveries = 'deliveries';
    case Endpoints = 'endpoints';
}

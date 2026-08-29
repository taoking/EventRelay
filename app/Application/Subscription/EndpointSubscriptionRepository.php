<?php

declare(strict_types=1);

namespace App\Application\Subscription;

use App\Domain\Endpoint\EndpointId;
use App\Domain\Subscription\EndpointSubscriptions;

interface EndpointSubscriptionRepository
{
    public function forEndpoint(EndpointId $endpointId): EndpointSubscriptions;

    public function replace(EndpointSubscriptions $subscriptions): void;
}

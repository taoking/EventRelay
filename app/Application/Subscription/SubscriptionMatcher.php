<?php

declare(strict_types=1);

namespace App\Application\Subscription;

use App\Domain\Endpoint\EndpointId;
use App\Domain\Event\EventType;

interface SubscriptionMatcher
{
    /**
     * @return list<EndpointId>
     */
    public function matchingActiveEndpointIds(EventType $eventType): array;
}

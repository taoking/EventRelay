<?php

declare(strict_types=1);

namespace App\Application\Subscription;

use App\Application\Endpoint\EndpointNotFound;
use App\Application\Endpoint\EndpointRepository;
use App\Domain\Subscription\EndpointSubscriptions;

final readonly class ReplaceEndpointSubscriptions
{
    public function __construct(
        private EndpointRepository $endpoints,
        private EndpointSubscriptionRepository $subscriptions,
    ) {}

    /**
     * @param  list<string>  $types
     */
    public function handle(string $id, array $types): EndpointSubscriptionData
    {
        $endpoint = $this->endpoints->find($id);

        if ($endpoint === null) {
            throw new EndpointNotFound($id);
        }

        $subscriptions = EndpointSubscriptions::replace($endpoint->id(), $types);
        $this->subscriptions->replace($subscriptions);

        return EndpointSubscriptionData::fromDomain($subscriptions);
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Subscription;

use App\Application\Endpoint\EndpointNotFound;
use App\Application\Endpoint\EndpointRepository;

final readonly class GetEndpointSubscriptions
{
    public function __construct(
        private EndpointRepository $endpoints,
        private EndpointSubscriptionRepository $subscriptions,
    ) {}

    public function handle(string $id): EndpointSubscriptionData
    {
        $endpoint = $this->endpoints->find($id);

        if ($endpoint === null) {
            throw new EndpointNotFound($id);
        }

        return EndpointSubscriptionData::fromDomain(
            $this->subscriptions->forEndpoint($endpoint->id()),
        );
    }
}

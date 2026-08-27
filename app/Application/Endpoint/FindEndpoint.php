<?php

declare(strict_types=1);

namespace App\Application\Endpoint;

final readonly class FindEndpoint
{
    public function __construct(
        private EndpointRepository $endpoints,
    ) {}

    public function handle(string $id): EndpointData
    {
        $endpoint = $this->endpoints->find($id);

        if ($endpoint === null) {
            throw new EndpointNotFound($id);
        }

        return EndpointData::fromDomain($endpoint);
    }
}

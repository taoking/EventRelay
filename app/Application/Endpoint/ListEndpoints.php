<?php

declare(strict_types=1);

namespace App\Application\Endpoint;

final readonly class ListEndpoints
{
    public function __construct(
        private EndpointRepository $endpoints,
    ) {}

    /**
     * @return list<EndpointData>
     */
    public function handle(): array
    {
        return array_map(EndpointData::fromDomain(...), $this->endpoints->all());
    }
}

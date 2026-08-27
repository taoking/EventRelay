<?php

declare(strict_types=1);

namespace App\Application\Endpoint;

use App\Domain\Endpoint\Endpoint;
use App\Domain\Endpoint\EndpointStatus;

final readonly class CreateEndpoint
{
    public function __construct(
        private EndpointRepository $endpoints,
    ) {}

    public function handle(string $name, string $url, ?string $status): EndpointData
    {
        $endpoint = Endpoint::create(
            $name,
            $url,
            $status === null ? EndpointStatus::Active : EndpointStatus::from($status),
        );

        return EndpointData::fromDomain($this->endpoints->save($endpoint));
    }
}

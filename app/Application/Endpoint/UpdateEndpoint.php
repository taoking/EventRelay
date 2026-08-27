<?php

declare(strict_types=1);

namespace App\Application\Endpoint;

use App\Domain\Endpoint\EndpointStatus;

final readonly class UpdateEndpoint
{
    public function __construct(
        private EndpointRepository $endpoints,
    ) {}

    public function handle(string $id, ?string $name, ?string $url, ?string $status): EndpointData
    {
        $endpoint = $this->endpoints->find($id);

        if ($endpoint === null) {
            throw new EndpointNotFound($id);
        }

        $updated = $endpoint->update(
            $name,
            $url,
            $status === null ? null : EndpointStatus::from($status),
        );

        return EndpointData::fromDomain($this->endpoints->save($updated));
    }
}

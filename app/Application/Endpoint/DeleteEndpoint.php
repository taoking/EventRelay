<?php

declare(strict_types=1);

namespace App\Application\Endpoint;

final readonly class DeleteEndpoint
{
    public function __construct(
        private EndpointRepository $endpoints,
    ) {}

    public function handle(string $id): void
    {
        $endpoint = $this->endpoints->find($id);

        if ($endpoint === null) {
            throw new EndpointNotFound($id);
        }

        $this->endpoints->delete($endpoint);
    }
}

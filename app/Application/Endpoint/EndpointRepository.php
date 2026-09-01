<?php

declare(strict_types=1);

namespace App\Application\Endpoint;

use App\Domain\Endpoint\Endpoint;

interface EndpointRepository
{
    public function save(Endpoint $endpoint): Endpoint;

    public function find(string $id): ?Endpoint;

    public function delete(Endpoint $endpoint): void;
}

<?php

declare(strict_types=1);

namespace App\Application\Endpoint;

use App\Domain\Endpoint\Endpoint;

final readonly class EndpointData
{
    public function __construct(
        public string $id,
        public string $name,
        public string $url,
        public string $status,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromDomain(Endpoint $endpoint): self
    {
        return new self(
            $endpoint->id()->toString(),
            $endpoint->name(),
            $endpoint->url(),
            $endpoint->status()->value,
            $endpoint->createdAt()->format(DATE_ATOM),
            $endpoint->updatedAt()->format(DATE_ATOM),
        );
    }
}

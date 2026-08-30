<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Domain\Delivery\Delivery;

final readonly class DeliveryData
{
    public function __construct(
        public string $id,
        public string $eventId,
        public string $endpointId,
        public string $targetUrl,
        public string $status,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromDomain(Delivery $delivery): self
    {
        return new self(
            $delivery->id()->toString(),
            $delivery->eventId()->toString(),
            $delivery->endpointId()->toString(),
            $delivery->targetUrl(),
            $delivery->status()->value,
            $delivery->createdAt()->format(DATE_ATOM),
            $delivery->updatedAt()->format(DATE_ATOM),
        );
    }
}

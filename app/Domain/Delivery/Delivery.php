<?php

declare(strict_types=1);

namespace App\Domain\Delivery;

use App\Domain\Endpoint\EndpointId;
use App\Domain\Event\EventId;
use DateTimeImmutable;
use DateTimeInterface;

final readonly class Delivery
{
    private function __construct(
        private DeliveryId $id,
        private EventId $eventId,
        private EndpointId $endpointId,
        private DeliveryStatus $status,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {}

    public static function create(EventId $eventId, EndpointId $endpointId): self
    {
        $now = new DateTimeImmutable;

        return new self(
            DeliveryId::generate(),
            $eventId,
            $endpointId,
            DeliveryStatus::Pending,
            $now,
            $now,
        );
    }

    public static function reconstitute(
        DeliveryId $id,
        EventId $eventId,
        EndpointId $endpointId,
        DeliveryStatus $status,
        DateTimeInterface $createdAt,
        DateTimeInterface $updatedAt,
    ): self {
        return new self(
            $id,
            $eventId,
            $endpointId,
            $status,
            DateTimeImmutable::createFromInterface($createdAt),
            DateTimeImmutable::createFromInterface($updatedAt),
        );
    }

    public function id(): DeliveryId
    {
        return $this->id;
    }

    public function eventId(): EventId
    {
        return $this->eventId;
    }

    public function endpointId(): EndpointId
    {
        return $this->endpointId;
    }

    public function status(): DeliveryStatus
    {
        return $this->status;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}

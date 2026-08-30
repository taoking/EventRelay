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
        private string $targetUrl,
        private DeliveryStatus $status,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {}

    public static function create(EventId $eventId, EndpointId $endpointId, string $targetUrl): self
    {
        $now = new DateTimeImmutable;

        return new self(
            DeliveryId::generate(),
            $eventId,
            $endpointId,
            $targetUrl,
            DeliveryStatus::Pending,
            $now,
            $now,
        );
    }

    public static function reconstitute(
        DeliveryId $id,
        EventId $eventId,
        EndpointId $endpointId,
        string $targetUrl,
        DeliveryStatus $status,
        DateTimeInterface $createdAt,
        DateTimeInterface $updatedAt,
    ): self {
        return new self(
            $id,
            $eventId,
            $endpointId,
            $targetUrl,
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

    public function targetUrl(): string
    {
        return $this->targetUrl;
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

    public function claim(): self
    {
        return $this->transitionTo(DeliveryStatus::Processing);
    }

    public function succeed(): self
    {
        return $this->transitionTo(DeliveryStatus::Succeeded);
    }

    public function fail(): self
    {
        return $this->transitionTo(DeliveryStatus::Failed);
    }

    private function transitionTo(DeliveryStatus $next): self
    {
        $allowed = match ($this->status) {
            DeliveryStatus::Pending => $next === DeliveryStatus::Processing,
            DeliveryStatus::Processing => in_array($next, [DeliveryStatus::Succeeded, DeliveryStatus::Failed], true),
            DeliveryStatus::Succeeded, DeliveryStatus::Failed => false,
        };

        if (! $allowed) {
            throw new \LogicException("Delivery transition {$this->status->value} → {$next->value} is not allowed.");
        }

        return new self(
            $this->id,
            $this->eventId,
            $this->endpointId,
            $this->targetUrl,
            $next,
            $this->createdAt,
            new DateTimeImmutable,
        );
    }
}

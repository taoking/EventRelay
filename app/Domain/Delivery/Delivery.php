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
        private ?DateTimeImmutable $nextAttemptAt,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
        if (($status === DeliveryStatus::RetryScheduled) !== ($nextAttemptAt !== null)) {
            throw new \LogicException('Only retry_scheduled deliveries may have next_attempt_at.');
        }
    }

    public static function create(EventId $eventId, EndpointId $endpointId, string $targetUrl): self
    {
        $now = new DateTimeImmutable;

        return new self(
            DeliveryId::generate(),
            $eventId,
            $endpointId,
            $targetUrl,
            DeliveryStatus::Pending,
            null,
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
        ?DateTimeInterface $nextAttemptAt = null,
    ): self {
        return new self(
            $id,
            $eventId,
            $endpointId,
            $targetUrl,
            $status,
            $nextAttemptAt === null ? null : DateTimeImmutable::createFromInterface($nextAttemptAt),
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

    public function nextAttemptAt(): ?DateTimeImmutable
    {
        return $this->nextAttemptAt;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function claim(?DateTimeInterface $at = null): self
    {
        if ($this->status === DeliveryStatus::RetryScheduled
            && $this->nextAttemptAt !== null
            && $this->nextAttemptAt > self::immutable($at)) {
            throw new \LogicException('A retry_scheduled delivery cannot be claimed before next_attempt_at.');
        }

        return $this->transitionTo(DeliveryStatus::Processing, null, $at);
    }

    public function succeed(?DateTimeInterface $at = null): self
    {
        return $this->transitionTo(DeliveryStatus::Succeeded, null, $at);
    }

    public function fail(?DateTimeInterface $at = null): self
    {
        return $this->transitionTo(DeliveryStatus::Failed, null, $at);
    }

    public function scheduleRetry(DateTimeInterface $nextAttemptAt, ?DateTimeInterface $at = null): self
    {
        return $this->transitionTo(DeliveryStatus::RetryScheduled, $nextAttemptAt, $at);
    }

    private function transitionTo(DeliveryStatus $next, ?DateTimeInterface $nextAttemptAt, ?DateTimeInterface $at): self
    {
        $allowed = match ($this->status) {
            DeliveryStatus::Pending => $next === DeliveryStatus::Processing,
            DeliveryStatus::RetryScheduled => $next === DeliveryStatus::Processing,
            DeliveryStatus::Processing => in_array($next, [DeliveryStatus::Succeeded, DeliveryStatus::Failed, DeliveryStatus::RetryScheduled], true),
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
            $nextAttemptAt === null ? null : DateTimeImmutable::createFromInterface($nextAttemptAt),
            $this->createdAt,
            self::immutable($at),
        );
    }

    private static function immutable(?DateTimeInterface $at): DateTimeImmutable
    {
        return $at === null ? new DateTimeImmutable : DateTimeImmutable::createFromInterface($at);
    }
}

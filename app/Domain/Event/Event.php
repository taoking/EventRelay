<?php

declare(strict_types=1);

namespace App\Domain\Event;

use DateTimeImmutable;
use DateTimeInterface;

final readonly class Event
{
    private function __construct(
        private EventId $id,
        private EventType $type,
        private EventPayload $payload,
        private DateTimeImmutable $createdAt,
    ) {}

    public static function create(string $type, mixed $payload): self
    {
        return new self(
            EventId::generate(),
            EventType::fromString($type),
            EventPayload::fromValue($payload),
            new DateTimeImmutable,
        );
    }

    public static function reconstitute(
        EventId $id,
        string $type,
        mixed $payload,
        DateTimeInterface $createdAt,
    ): self {
        return new self(
            $id,
            EventType::fromString($type),
            EventPayload::fromValue($payload),
            DateTimeImmutable::createFromInterface($createdAt),
        );
    }

    public function id(): EventId
    {
        return $this->id;
    }

    public function type(): string
    {
        return $this->type->toString();
    }

    public function eventType(): EventType
    {
        return $this->type;
    }

    public function payload(): \stdClass
    {
        return $this->payload->toObject();
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Event;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

final readonly class Event
{
    private function __construct(
        private EventId $id,
        private string $type,
        private EventPayload $payload,
        private DateTimeImmutable $createdAt,
    ) {}

    public static function create(string $type, mixed $payload): self
    {
        return new self(
            EventId::generate(),
            self::normaliseType($type),
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
            self::normaliseType($type),
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

    private static function normaliseType(string $type): string
    {
        if (
            $type === ''
            || mb_strlen($type) > 120
            || preg_match('/^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$/', $type) !== 1
        ) {
            throw new InvalidArgumentException('Event type has an invalid format.');
        }

        return $type;
    }
}

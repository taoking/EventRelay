<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Domain\Event\Event;

final readonly class EventData
{
    public function __construct(
        public string $id,
        public string $type,
        public \stdClass $payload,
        public string $createdAt,
    ) {}

    public static function fromDomain(Event $event): self
    {
        return new self(
            $event->id()->toString(),
            $event->type(),
            $event->payload(),
            $event->createdAt()->format(DATE_ATOM),
        );
    }
}

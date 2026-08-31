<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Domain\Event\EventId;

final readonly class EventIngressIdempotency
{
    public function __construct(
        public string $keyDigest,
        public string $requestFingerprint,
        public EventId $eventId,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Application\DeadLetter;

use DateTimeImmutable;

final readonly class DeadLetterItem
{
    public function __construct(
        public string $deliveryId,
        public string $eventId,
        public string $endpointId,
        public ?string $replayOfDeliveryId,
        public string $eventType,
        public int $attemptCount,
        public int $lastAttemptNumber,
        public string $failureType,
        public ?int $responseStatus,
        public DateTimeImmutable $failedAt,
        public DateTimeImmutable $createdAt,
    ) {}
}

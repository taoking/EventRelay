<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Domain\DeliveryAttempt\DeliveryAttempt;

final readonly class DeliveryAttemptData
{
    public function __construct(
        public string $id,
        public int $attemptNumber,
        public string $status,
        public ?int $responseStatus,
        public ?string $failureType,
        public ?string $failureMessage,
        public ?int $durationMs,
        public string $startedAt,
        public ?string $finishedAt,
    ) {}

    public static function fromDomain(DeliveryAttempt $attempt): self
    {
        return new self(
            $attempt->id()->toString(),
            $attempt->number(),
            $attempt->status()->value,
            $attempt->responseStatus(),
            $attempt->failureType()?->value,
            $attempt->failureMessage(),
            $attempt->durationMs(),
            $attempt->startedAt()->format(DATE_ATOM),
            $attempt->finishedAt()?->format(DATE_ATOM),
        );
    }
}

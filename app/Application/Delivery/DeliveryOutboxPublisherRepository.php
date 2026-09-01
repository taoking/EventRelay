<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use DateTimeImmutable;

interface DeliveryOutboxPublisherRepository
{
    /**
     * @return list<ClaimedDeliveryOutboxMessage>
     */
    public function claim(int $limit, DateTimeImmutable $now, DateTimeImmutable $leaseUntil): array;

    public function markPublished(string $publicId, string $claimToken, DateTimeImmutable $now): bool;

    public function releaseAfterKnownPublicationFailure(
        string $publicId,
        string $claimToken,
        string $transport,
        DateTimeImmutable $now,
    ): bool;
}

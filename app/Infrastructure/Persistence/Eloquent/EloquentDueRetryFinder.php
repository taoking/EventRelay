<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\Delivery\DueRetryFinder;
use App\Domain\Delivery\DeliveryId;
use App\Domain\Delivery\DeliveryStatus;
use DateTimeImmutable;
use LogicException;

final class EloquentDueRetryFinder implements DueRetryFinder
{
    public function findDue(DateTimeImmutable $now, int $limit): array
    {
        $ids = [];

        foreach (DeliveryRecord::query()
            ->where('status', DeliveryStatus::RetryScheduled->value)
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '<=', $now)
            ->orderBy('next_attempt_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('public_id') as $publicId) {
            if (! is_string($publicId)) {
                throw new LogicException('Persisted due retry delivery public IDs must be strings.');
            }

            $ids[] = DeliveryId::fromString($publicId);
        }

        return $ids;
    }
}

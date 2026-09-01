<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\Delivery\StaleDeliveryFinder;
use App\Domain\Delivery\DeliveryId;
use DateTimeImmutable;
use LogicException;

final class EloquentStaleDeliveryFinder implements StaleDeliveryFinder
{
    public function findStale(DateTimeImmutable $cutoff, int $limit): array
    {
        $ids = [];

        foreach (EloquentStaleDeliveryQuery::apply(DeliveryRecord::query(), $cutoff)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('public_id') as $publicId) {
            if (! is_string($publicId)) {
                throw new LogicException('Persisted stale delivery public IDs must be strings.');
            }

            $ids[] = DeliveryId::fromString($publicId);
        }

        return $ids;
    }
}

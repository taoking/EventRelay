<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\Delivery\PendingDeliveryFinder;
use App\Domain\Delivery\DeliveryId;
use App\Domain\Delivery\DeliveryStatus;
use LogicException;

final class EloquentPendingDeliveryFinder implements PendingDeliveryFinder
{
    public function findPending(int $limit): array
    {
        $ids = [];

        foreach (DeliveryRecord::query()
            ->where('status', DeliveryStatus::Pending->value)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('public_id') as $publicId) {
            if (! is_string($publicId)) {
                throw new LogicException('Persisted pending delivery public IDs must be strings.');
            }

            $ids[] = DeliveryId::fromString($publicId);
        }

        return $ids;
    }
}

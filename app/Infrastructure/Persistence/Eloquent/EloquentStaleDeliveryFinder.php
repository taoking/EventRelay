<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\Delivery\StaleDeliveryFinder;
use App\Domain\Delivery\DeliveryId;
use App\Domain\Delivery\DeliveryStatus;
use App\Domain\DeliveryAttempt\DeliveryAttemptStatus;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use LogicException;

final class EloquentStaleDeliveryFinder implements StaleDeliveryFinder
{
    public function findStale(DateTimeImmutable $cutoff, int $limit): array
    {
        $ids = [];

        foreach (DeliveryRecord::query()
            ->where('status', DeliveryStatus::Processing->value)
            ->whereExists(function (Builder $query) use ($cutoff): void {
                $query->selectRaw('1')
                    ->from('delivery_attempts as latest_attempt')
                    ->whereColumn('latest_attempt.delivery_id', 'deliveries.id')
                    ->where('latest_attempt.status', DeliveryAttemptStatus::Started->value)
                    ->where('latest_attempt.started_at', '<=', $cutoff)
                    ->whereNotExists(function (Builder $newer): void {
                        $newer->selectRaw('1')
                            ->from('delivery_attempts as newer_attempt')
                            ->whereColumn('newer_attempt.delivery_id', 'latest_attempt.delivery_id')
                            ->whereColumn('newer_attempt.attempt_number', '>', 'latest_attempt.attempt_number');
                    });
            })
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

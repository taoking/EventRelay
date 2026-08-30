<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\Delivery\DeliveryExecutionIntent;
use App\Application\Delivery\DeliveryOutboxIntentFinder;
use App\Domain\Delivery\DeliveryId;
use App\Domain\Delivery\DeliveryStatus;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

final class EloquentDeliveryOutboxIntentFinder implements DeliveryOutboxIntentFinder
{
    public function findPendingInitial(int $limit): array
    {
        return array_values(DeliveryRecord::query()
            ->where('status', DeliveryStatus::Pending->value)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('public_id')
            ->map(function (mixed $publicId): DeliveryExecutionIntent {
                if (! is_string($publicId)) {
                    throw new LogicException('Persisted pending delivery public IDs must be strings.');
                }

                return new DeliveryExecutionIntent(DeliveryId::fromString($publicId), 1, null);
            })
            ->all());
    }

    public function findDueRetries(DateTimeImmutable $now, int $limit): array
    {
        return array_values(DB::table('deliveries')
            ->leftJoin('delivery_attempts', 'delivery_attempts.delivery_id', '=', 'deliveries.id')
            ->where('deliveries.status', DeliveryStatus::RetryScheduled->value)
            ->whereNotNull('deliveries.next_attempt_at')
            ->where('deliveries.next_attempt_at', '<=', $now)
            ->groupBy('deliveries.id', 'deliveries.public_id', 'deliveries.next_attempt_at')
            ->orderBy('deliveries.next_attempt_at')
            ->orderBy('deliveries.id')
            ->limit($limit)
            ->select([
                'deliveries.public_id',
                'deliveries.next_attempt_at',
                DB::raw('COALESCE(MAX(delivery_attempts.attempt_number), 0) + 1 AS next_attempt_number'),
            ])
            ->get()
            ->map(function (object $row): DeliveryExecutionIntent {
                if (! is_string($row->public_id) || ! is_string($row->next_attempt_at)) {
                    throw new LogicException('Persisted due retry fields are required.');
                }

                return new DeliveryExecutionIntent(
                    DeliveryId::fromString($row->public_id),
                    (int) $row->next_attempt_number,
                    new DateTimeImmutable($row->next_attempt_at),
                );
            })
            ->all());
    }
}

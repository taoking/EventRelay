<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\Delivery\DeliveryStatus;
use App\Domain\DeliveryAttempt\DeliveryAttemptStatus;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

final class EloquentStaleDeliveryQuery
{
    /**
     * @param  Builder<DeliveryRecord>  $query
     * @return Builder<DeliveryRecord>
     */
    public static function apply(Builder $query, DateTimeImmutable $cutoff): Builder
    {
        return $query
            ->where('status', DeliveryStatus::Processing->value)
            ->whereExists(function (QueryBuilder $query) use ($cutoff): void {
                $query->selectRaw('1')
                    ->from('delivery_attempts as latest_attempt')
                    ->whereColumn('latest_attempt.delivery_id', 'deliveries.id')
                    ->where('latest_attempt.status', DeliveryAttemptStatus::Started->value)
                    ->where('latest_attempt.started_at', '<=', $cutoff)
                    ->whereNotExists(function (QueryBuilder $newer): void {
                        $newer->selectRaw('1')
                            ->from('delivery_attempts as newer_attempt')
                            ->whereColumn('newer_attempt.delivery_id', 'latest_attempt.delivery_id')
                            ->whereColumn('newer_attempt.attempt_number', '>', 'latest_attempt.attempt_number');
                    });
            });
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\Delivery\DeliveryStatus;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;

final class EloquentDueRetryQuery
{
    /**
     * @param  Builder<DeliveryRecord>  $query
     * @return Builder<DeliveryRecord>
     */
    public static function apply(Builder $query, DateTimeImmutable $now): Builder
    {
        return $query
            ->where('status', DeliveryStatus::RetryScheduled->value)
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '<=', $now);
    }
}

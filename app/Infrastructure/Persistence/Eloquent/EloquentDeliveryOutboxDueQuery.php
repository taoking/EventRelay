<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;

final class EloquentDeliveryOutboxDueQuery
{
    public const string EffectiveDueAtExpression = 'COALESCE(available_at, created_at)';

    /**
     * @param  Builder<DeliveryOutboxMessageRecord>  $query
     * @return Builder<DeliveryOutboxMessageRecord>
     */
    public static function apply(Builder $query, DateTimeImmutable $now): Builder
    {
        return $query
            ->where(function (Builder $query) use ($now): void {
                $query->where('status', DeliveryOutboxMessageStatus::Pending->value)
                    ->orWhere(function (Builder $query) use ($now): void {
                        $query->where('status', DeliveryOutboxMessageStatus::Publishing->value)
                            ->whereNotNull('claimed_until')
                            ->where('claimed_until', '<=', $now);
                    });
            })
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('available_at')
                    ->orWhere('available_at', '<=', $now);
            });
    }

    public static function effectiveDueAtExpression(): string
    {
        return self::EffectiveDueAtExpression;
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\Delivery\ClaimedDeliveryOutboxMessage;
use App\Application\Delivery\DeliveryExecutionIntent;
use App\Application\Delivery\DeliveryOutboxPublisherRepository;
use App\Domain\Delivery\DeliveryId;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentDeliveryOutboxPublisherRepository implements DeliveryOutboxPublisherRepository
{
    public function claim(int $limit, DateTimeImmutable $now, DateTimeImmutable $leaseUntil): array
    {
        return DB::transaction(function () use ($limit, $now, $leaseUntil): array {
            $query = DeliveryOutboxMessageRecord::query()
                ->where(function (Builder $query) use ($now): void {
                    $query->where('status', 'pending')
                        ->orWhere(function (Builder $query) use ($now): void {
                            $query->where('status', 'publishing')
                                ->whereNotNull('claimed_until')
                                ->where('claimed_until', '<=', $now);
                        });
                })
                ->orderBy('id')
                ->limit($limit);

            if (DB::connection()->getDriverName() === 'mysql') {
                $query->lock('for update skip locked');
            } else {
                $query->lockForUpdate();
            }

            $messages = [];
            foreach ($query->get() as $record) {
                $token = (string) Str::uuid();
                $record->fill([
                    'status' => 'publishing',
                    'claim_token' => $token,
                    'claimed_until' => $leaseUntil,
                    'publication_attempts' => $record->publication_attempts + 1,
                    'last_error_code' => null,
                    'updated_at' => $now,
                ]);
                $record->save();

                $messages[] = new ClaimedDeliveryOutboxMessage(
                    $record->public_id,
                    new DeliveryExecutionIntent(
                        DeliveryId::fromString($this->deliveryPublicId($record->delivery_id)),
                        $record->attempt_number,
                        $record->available_at === null ? null : DateTimeImmutable::createFromInterface($record->available_at),
                    ),
                    $token,
                );
            }

            return $messages;
        });
    }

    public function markPublished(string $publicId, string $claimToken, DateTimeImmutable $now): bool
    {
        return DeliveryOutboxMessageRecord::query()
            ->where('public_id', $publicId)
            ->where('status', 'publishing')
            ->where('claim_token', $claimToken)
            ->update([
                'status' => 'published',
                'claim_token' => null,
                'claimed_until' => null,
                'published_at' => $now,
                'updated_at' => $now,
            ]) === 1;
    }

    public function releaseAfterKnownPublicationFailure(string $publicId, string $claimToken, DateTimeImmutable $now): bool
    {
        return DeliveryOutboxMessageRecord::query()
            ->where('public_id', $publicId)
            ->where('status', 'publishing')
            ->where('claim_token', $claimToken)
            ->update([
                'status' => 'pending',
                'claim_token' => null,
                'claimed_until' => null,
                'last_error_code' => 'redis_unavailable',
                'updated_at' => $now,
            ]) === 1;
    }

    private function deliveryPublicId(int $internalDeliveryId): string
    {
        $publicId = DeliveryRecord::query()->whereKey($internalDeliveryId)->value('public_id');

        if (! is_string($publicId)) {
            throw new \LogicException('An outbox message must retain its delivery.');
        }

        return $publicId;
    }
}

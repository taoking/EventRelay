<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\Delivery\DeliveryExecutionIntent;
use App\Application\Delivery\DeliveryOutboxRecovery;
use App\Domain\Delivery\DeliveryStatus;
use App\Domain\DeliveryAttempt\DeliveryAttemptStatus;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentDeliveryOutboxRecovery implements DeliveryOutboxRecovery
{
    public function ensureRecoverable(DeliveryExecutionIntent $intent, DateTimeImmutable $now): bool
    {
        return DB::transaction(function () use ($intent, $now): bool {
            $delivery = DeliveryRecord::query()
                ->where('public_id', $intent->deliveryId->toString())
                ->lockForUpdate()
                ->first();

            if ($delivery === null) {
                return false;
            }

            $latestAttempt = DeliveryAttemptRecord::query()
                ->where('delivery_id', $delivery->getKey())
                ->orderByDesc('attempt_number')
                ->lockForUpdate()
                ->first();

            if (! $this->isCurrentUnstartedIntent($delivery, $latestAttempt, $intent, $now)) {
                return false;
            }

            $record = DeliveryOutboxMessageRecord::query()
                ->where('dedupe_key', $intent->dedupeKey())
                ->lockForUpdate()
                ->first();

            if ($record === null) {
                try {
                    $record = new DeliveryOutboxMessageRecord;
                    $record->fill([
                        'public_id' => (string) Str::uuid(),
                        'delivery_id' => $delivery->getKey(),
                        'message_type' => DeliveryExecutionIntent::MessageType,
                        'dedupe_key' => $intent->dedupeKey(),
                        'attempt_number' => $intent->attemptNumber,
                        'available_at' => $intent->availableAt,
                        'status' => 'pending',
                        'publication_attempts' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $record->save();

                    return true;
                } catch (QueryException $exception) {
                    if (! $this->isDedupeUniqueViolation($exception)) {
                        throw $exception;
                    }

                    $record = DeliveryOutboxMessageRecord::query()
                        ->where('dedupe_key', $intent->dedupeKey())
                        ->lockForUpdate()
                        ->first();

                    if ($record === null) {
                        throw $exception;
                    }
                }
            }

            if ($record->status !== 'published') {
                return true;
            }

            $record->fill([
                'status' => 'pending',
                'claim_token' => null,
                'claimed_until' => null,
                'published_at' => null,
                'last_error_code' => 'broker_job_lost',
                'updated_at' => $now,
            ]);
            $record->save();

            return true;
        });
    }

    private function isCurrentUnstartedIntent(
        DeliveryRecord $delivery,
        ?DeliveryAttemptRecord $latestAttempt,
        DeliveryExecutionIntent $intent,
        DateTimeImmutable $now,
    ): bool {
        if ($delivery->status === DeliveryStatus::Pending->value) {
            return $intent->attemptNumber === 1
                && $intent->availableAt === null
                && $latestAttempt === null;
        }

        if ($delivery->status !== DeliveryStatus::RetryScheduled->value
            || $delivery->next_attempt_at === null
            || $delivery->next_attempt_at > $now
            || $latestAttempt === null
            || $latestAttempt->attempt_number + 1 !== $intent->attemptNumber
            || ($latestAttempt->status !== DeliveryAttemptStatus::Failed->value
                && $latestAttempt->status !== DeliveryAttemptStatus::Abandoned->value)) {
            return false;
        }

        return $intent->availableAt == $delivery->next_attempt_at;
    }

    private function isDedupeUniqueViolation(QueryException $exception): bool
    {
        return str_contains($exception->getMessage(), 'delivery_outbox_messages_dedupe_key_unique')
            || str_contains($exception->getMessage(), 'UNIQUE constraint failed: delivery_outbox_messages.dedupe_key');
    }
}

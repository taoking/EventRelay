<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\Delivery\DeliveryExecutionIntent;
use App\Application\Delivery\DeliveryOutboxWriter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use LogicException;

final class EloquentDeliveryOutboxWriter implements DeliveryOutboxWriter
{
    public function schedule(DeliveryExecutionIntent $intent, \DateTimeImmutable $now): void
    {
        $deliveryId = DeliveryRecord::query()
            ->where('public_id', $intent->deliveryId->toString())
            ->value('id');

        if ($deliveryId === null) {
            throw new LogicException('A delivery execution intent requires a persisted delivery.');
        }

        try {
            $record = new DeliveryOutboxMessageRecord;
            $record->fill([
                'public_id' => (string) Str::uuid(),
                'delivery_id' => $deliveryId,
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
        } catch (QueryException $exception) {
            if (! $this->isDedupeUniqueViolation($exception)) {
                throw $exception;
            }
        }
    }

    private function isDedupeUniqueViolation(QueryException $exception): bool
    {
        return str_contains($exception->getMessage(), 'delivery_outbox_messages_dedupe_key_unique')
            || str_contains($exception->getMessage(), 'UNIQUE constraint failed: delivery_outbox_messages.dedupe_key');
    }
}

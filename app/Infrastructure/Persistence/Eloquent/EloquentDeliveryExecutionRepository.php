<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\Delivery\ClaimedDelivery;
use App\Application\Delivery\DeliveryExecutionRepository;
use App\Domain\Delivery\Delivery;
use App\Domain\Delivery\DeliveryId;
use App\Domain\Delivery\DeliveryStatus;
use App\Domain\DeliveryAttempt\DeliveryAttempt;
use App\Domain\DeliveryAttempt\DeliveryAttemptId;
use App\Domain\DeliveryAttempt\DeliveryAttemptStatus;
use App\Domain\DeliveryAttempt\DeliveryFailureType;
use App\Domain\Endpoint\EndpointId;
use App\Domain\Event\EventId;
use Illuminate\Support\Facades\DB;
use LogicException;

final class EloquentDeliveryExecutionRepository implements DeliveryExecutionRepository
{
    public function claim(DeliveryId $deliveryId): ?ClaimedDelivery
    {
        return DB::transaction(function () use ($deliveryId): ?ClaimedDelivery {
            $claimed = DeliveryRecord::query()
                ->where('public_id', $deliveryId->toString())
                ->where('status', DeliveryStatus::Pending->value)
                ->update([
                    'status' => DeliveryStatus::Processing->value,
                    'updated_at' => now(),
                ]);

            if ($claimed !== 1) {
                return null;
            }

            $record = DeliveryRecord::query()->where('public_id', $deliveryId->toString())->firstOrFail();
            $attempt = DeliveryAttempt::start($deliveryId);
            $attemptRecord = new DeliveryAttemptRecord;
            $attemptRecord->fill([
                'public_id' => $attempt->id()->toString(),
                'delivery_id' => $record->getKey(),
                'attempt_number' => $attempt->number(),
                'status' => $attempt->status()->value,
                'started_at' => $attempt->startedAt(),
            ]);
            $attemptRecord->save();

            return new ClaimedDelivery($this->delivery($record), $attempt);
        });
    }

    public function finalize(Delivery $delivery, DeliveryAttempt $attempt): void
    {
        DB::transaction(function () use ($delivery, $attempt): void {
            $attemptRecord = DeliveryAttemptRecord::query()
                ->where('public_id', $attempt->id()->toString())
                ->firstOrFail();
            $attemptRecord->fill([
                'status' => $attempt->status()->value,
                'response_status' => $attempt->responseStatus(),
                'failure_type' => $attempt->failureType()?->value,
                'failure_message' => $attempt->failureMessage(),
                'duration_ms' => $attempt->durationMs(),
                'finished_at' => $attempt->finishedAt(),
            ]);
            $attemptRecord->save();

            $finalized = DeliveryRecord::query()
                ->where('public_id', $delivery->id()->toString())
                ->where('status', DeliveryStatus::Processing->value)
                ->update([
                    'status' => $delivery->status()->value,
                    'updated_at' => $delivery->updatedAt(),
                ]);

            if ($finalized !== 1) {
                throw new LogicException('A claimed delivery must still be processing when finalized.');
            }
        });
    }

    public function attempts(DeliveryId $deliveryId): array
    {
        $internalId = DeliveryRecord::query()->where('public_id', $deliveryId->toString())->value('id');

        if ($internalId === null) {
            return [];
        }

        return array_values(
            DeliveryAttemptRecord::query()
                ->where('delivery_id', $internalId)
                ->orderBy('attempt_number')
                ->get()
                ->map(fn (DeliveryAttemptRecord $record): DeliveryAttempt => $this->attempt($record, $deliveryId))
                ->all(),
        );
    }

    private function delivery(DeliveryRecord $record): Delivery
    {
        if ($record->created_at === null || $record->updated_at === null || $record->target_url === null) {
            throw new LogicException('Persisted delivery fields are required.');
        }

        $eventId = EventRecord::query()->findOrFail($record->event_id)->public_id;
        $endpointId = EndpointRecord::query()->withTrashed()->findOrFail($record->endpoint_id)->public_id;

        return Delivery::reconstitute(
            DeliveryId::fromString($record->public_id),
            EventId::fromString($eventId),
            EndpointId::fromString($endpointId),
            $record->target_url,
            DeliveryStatus::from($record->status),
            $record->created_at,
            $record->updated_at,
        );
    }

    private function attempt(DeliveryAttemptRecord $record, DeliveryId $deliveryId): DeliveryAttempt
    {
        return DeliveryAttempt::reconstitute(
            DeliveryAttemptId::fromString($record->public_id),
            $deliveryId,
            $record->attempt_number,
            DeliveryAttemptStatus::from($record->status),
            $record->response_status,
            $record->failure_type === null ? null : DeliveryFailureType::from($record->failure_type),
            $record->failure_message,
            $record->duration_ms,
            $record->started_at,
            $record->finished_at,
        );
    }
}

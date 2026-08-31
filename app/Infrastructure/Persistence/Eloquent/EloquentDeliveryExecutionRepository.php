<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\Delivery\ClaimedDelivery;
use App\Application\Delivery\DeliveryExecutionConflict;
use App\Application\Delivery\DeliveryExecutionIntent;
use App\Application\Delivery\DeliveryExecutionRepository;
use App\Application\Delivery\DeliveryOutboxWriter;
use App\Application\Delivery\DeliveryRetryPolicy;
use App\Application\Delivery\StaleRecoveryResult;
use App\Domain\Delivery\Delivery;
use App\Domain\Delivery\DeliveryId;
use App\Domain\Delivery\DeliveryStatus;
use App\Domain\DeliveryAttempt\DeliveryAttempt;
use App\Domain\DeliveryAttempt\DeliveryAttemptId;
use App\Domain\DeliveryAttempt\DeliveryAttemptStatus;
use App\Domain\DeliveryAttempt\DeliveryFailureType;
use App\Domain\Endpoint\EndpointId;
use App\Domain\EndpointSigningSecret\EndpointSigningSecretId;
use App\Domain\Event\EventId;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

final class EloquentDeliveryExecutionRepository implements DeliveryExecutionRepository
{
    public function __construct(
        private readonly DeliveryOutboxWriter $outbox,
    ) {}

    public function claim(DeliveryId $deliveryId, DateTimeImmutable $now): ?ClaimedDelivery
    {
        return DB::transaction(function () use ($deliveryId, $now): ?ClaimedDelivery {
            $record = DeliveryRecord::query()
                ->where('public_id', $deliveryId->toString())
                ->lockForUpdate()
                ->first();

            if ($record === null) {
                return null;
            }

            $status = DeliveryStatus::from($record->status);
            $canClaim = $status === DeliveryStatus::Pending
                || ($status === DeliveryStatus::RetryScheduled
                    && $record->next_attempt_at !== null
                    && $record->next_attempt_at <= $now);

            if (! $canClaim) {
                return null;
            }

            $latestAttempt = DeliveryAttemptRecord::query()
                ->where('delivery_id', $record->getKey())
                ->orderByDesc('attempt_number')
                ->lockForUpdate()
                ->first();
            $attemptNumber = $latestAttempt === null ? 1 : $latestAttempt->attempt_number + 1;

            if ($attemptNumber > DeliveryRetryPolicy::MAX_ATTEMPTS) {
                throw new LogicException('Delivery attempt budget cannot exceed its maximum.');
            }

            $record->fill([
                'status' => DeliveryStatus::Processing->value,
                'next_attempt_at' => null,
                'updated_at' => $now,
            ]);
            $record->save();

            $attempt = DeliveryAttempt::start($deliveryId, $attemptNumber, $now);
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
        $this->finalizeInTransaction($delivery, $attempt, null);
    }

    public function finalizeAndScheduleRetry(
        Delivery $delivery,
        DeliveryAttempt $attempt,
        DeliveryExecutionIntent $intent,
    ): void {
        if ($intent->deliveryId->toString() !== $delivery->id()->toString()
            || $intent->attemptNumber !== $attempt->number() + 1
            || $intent->availableAt != $delivery->nextAttemptAt()) {
            throw new LogicException('A retry execution intent must match the finalized delivery and next attempt.');
        }

        $this->finalizeInTransaction($delivery, $attempt, $intent);
    }

    private function finalizeInTransaction(
        Delivery $delivery,
        DeliveryAttempt $attempt,
        ?DeliveryExecutionIntent $intent,
    ): void {
        DB::transaction(function () use ($delivery, $attempt, $intent): void {
            $deliveryRecord = DeliveryRecord::query()
                ->where('public_id', $delivery->id()->toString())
                ->lockForUpdate()
                ->first();

            if ($deliveryRecord === null || $deliveryRecord->status !== DeliveryStatus::Processing->value) {
                throw new DeliveryExecutionConflict('A delivery can only be finalized while processing.');
            }

            $attemptRecord = DeliveryAttemptRecord::query()
                ->where('public_id', $attempt->id()->toString())
                ->lockForUpdate()
                ->first();

            if ($attemptRecord === null || $attemptRecord->status !== DeliveryAttemptStatus::Started->value) {
                throw new DeliveryExecutionConflict('A delivery attempt can only be finalized while started.');
            }

            $attemptRecord->fill([
                'status' => $attempt->status()->value,
                'response_status' => $attempt->responseStatus(),
                'failure_type' => $attempt->failureType()?->value,
                'failure_message' => $attempt->failureMessage(),
                'duration_ms' => $attempt->durationMs(),
                'finished_at' => $attempt->finishedAt(),
            ]);
            $attemptRecord->save();

            $deliveryRecord->fill([
                'status' => $delivery->status()->value,
                'next_attempt_at' => $delivery->nextAttemptAt(),
                'updated_at' => $delivery->updatedAt(),
            ]);
            $deliveryRecord->save();

            if ($intent !== null) {
                $this->outbox->schedule($intent, $delivery->updatedAt());
            }
        });
    }

    public function recoverStale(
        DeliveryId $deliveryId,
        DateTimeImmutable $cutoff,
        DateTimeImmutable $now,
        DeliveryRetryPolicy $policy,
    ): ?StaleRecoveryResult {
        return DB::transaction(function () use ($deliveryId, $cutoff, $now, $policy): ?StaleRecoveryResult {
            $deliveryRecord = DeliveryRecord::query()
                ->where('public_id', $deliveryId->toString())
                ->lockForUpdate()
                ->first();

            if ($deliveryRecord === null || $deliveryRecord->status !== DeliveryStatus::Processing->value) {
                return null;
            }

            $attemptRecord = DeliveryAttemptRecord::query()
                ->where('delivery_id', $deliveryRecord->getKey())
                ->orderByDesc('attempt_number')
                ->lockForUpdate()
                ->first();

            if ($attemptRecord === null
                || $attemptRecord->status !== DeliveryAttemptStatus::Started->value
                || $attemptRecord->started_at > $cutoff) {
                return null;
            }

            $delivery = $this->delivery($deliveryRecord);
            $attempt = $this->attempt($attemptRecord, $deliveryId);
            $abandoned = $attempt->abandon('Delivery processing exceeded the stale threshold.', $now);
            $decision = $policy->forStaleProcessing($attempt->number());
            $nextAttemptAt = $decision->shouldRetry ? $now->modify("+{$decision->delaySeconds} seconds") : null;
            $recoveredDelivery = $nextAttemptAt === null
                ? $delivery->fail($now)
                : $delivery->scheduleRetry($nextAttemptAt, $now);

            $attemptRecord->fill([
                'status' => $abandoned->status()->value,
                'failure_type' => $abandoned->failureType()?->value,
                'failure_message' => $abandoned->failureMessage(),
                'finished_at' => $abandoned->finishedAt(),
            ]);
            $attemptRecord->save();
            $deliveryRecord->fill([
                'status' => $recoveredDelivery->status()->value,
                'next_attempt_at' => $recoveredDelivery->nextAttemptAt(),
                'updated_at' => $recoveredDelivery->updatedAt(),
            ]);
            $deliveryRecord->save();

            if ($nextAttemptAt !== null) {
                $this->outbox->schedule(
                    new DeliveryExecutionIntent($deliveryId, $attempt->number() + 1, $nextAttemptAt),
                    $now,
                );
            }

            return new StaleRecoveryResult($recoveredDelivery, $abandoned, $nextAttemptAt);
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
            $record->signing_secret_id === null ? null : EndpointSigningSecretId::fromString($this->signingSecretPublicId($record->signing_secret_id)),
            DeliveryStatus::from($record->status),
            $record->created_at,
            $record->updated_at,
            $record->next_attempt_at,
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

    private function signingSecretPublicId(int $secretId): string
    {
        $record = EndpointSigningSecretRecord::query()->find($secretId);

        if ($record === null) {
            throw new LogicException('Persisted signing-secret reference is missing.');
        }

        return $record->public_id;
    }
}

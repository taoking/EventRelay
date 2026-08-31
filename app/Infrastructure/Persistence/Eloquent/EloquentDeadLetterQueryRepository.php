<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\DeadLetter\DeadLetterConsistencyViolation;
use App\Application\DeadLetter\DeadLetterCursor;
use App\Application\DeadLetter\DeadLetterFilter;
use App\Application\DeadLetter\DeadLetterItem;
use App\Application\DeadLetter\DeadLetterQueryRepository;
use App\Domain\Delivery\DeliveryStatus;
use App\Domain\DeliveryAttempt\DeliveryAttemptStatus;
use App\Domain\DeliveryAttempt\DeliveryFailureType;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

final class EloquentDeadLetterQueryRepository implements DeadLetterQueryRepository
{
    public function find(DeadLetterFilter $filter, ?DeadLetterCursor $cursor): array
    {
        $latestAttemptNumbers = DB::table('delivery_attempts as latest_attempt_numbers')
            ->select('latest_attempt_numbers.delivery_id')
            ->selectRaw('MAX(latest_attempt_numbers.attempt_number) as attempt_number')
            ->groupBy('latest_attempt_numbers.delivery_id');
        $attemptCounts = DB::table('delivery_attempts as attempt_counts')
            ->select('attempt_counts.delivery_id')
            ->selectRaw('COUNT(*) as attempt_count')
            ->groupBy('attempt_counts.delivery_id');

        $query = DB::table('deliveries as deliveries')
            ->select([
                'deliveries.public_id as delivery_id',
                'events.public_id as event_id',
                'endpoints.public_id as endpoint_id',
                'replay_source.public_id as replay_of_delivery_id',
                'events.type as event_type',
                'attempt_counts.attempt_count',
                'latest_attempt.attempt_number as last_attempt_number',
                'latest_attempt.status as last_attempt_status',
                'latest_attempt.failure_type',
                'latest_attempt.response_status',
                'latest_attempt.finished_at as failed_at',
                'deliveries.created_at',
            ])
            ->join('events', 'events.id', '=', 'deliveries.event_id')
            ->join('endpoints', 'endpoints.id', '=', 'deliveries.endpoint_id')
            ->leftJoin('deliveries as replay_source', 'replay_source.id', '=', 'deliveries.replay_of_delivery_id')
            ->leftJoinSub($latestAttemptNumbers, 'latest_attempt_number', function (JoinClause $join): void {
                $join->on('latest_attempt_number.delivery_id', '=', 'deliveries.id');
            })
            ->leftJoin('delivery_attempts as latest_attempt', function (JoinClause $join): void {
                $join->on('latest_attempt.delivery_id', '=', 'latest_attempt_number.delivery_id')
                    ->on('latest_attempt.attempt_number', '=', 'latest_attempt_number.attempt_number');
            })
            ->leftJoinSub($attemptCounts, 'attempt_counts', function (JoinClause $join): void {
                $join->on('attempt_counts.delivery_id', '=', 'deliveries.id');
            })
            ->where('deliveries.status', DeliveryStatus::Failed->value);

        $this->applyFilters($query, $filter);
        if ($cursor !== null) {
            $this->applyCursor($query, $cursor);
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            $query->orderByRaw('BINARY deliveries.public_id DESC');
        } else {
            $query->orderByDesc('deliveries.public_id');
        }

        return array_values(array_map(
            $this->toItem(...),
            $query
                ->orderByDesc('latest_attempt.finished_at')
                ->limit($filter->limit + 1)
                ->get()
                ->all(),
        ));
    }

    private function applyFilters(Builder $query, DeadLetterFilter $filter): void
    {
        if ($filter->endpointId !== null) {
            $query->where('endpoints.public_id', $filter->endpointId);
        }
        if ($filter->eventType !== null) {
            $query->where('events.type', $filter->eventType);
        }
        if ($filter->failureType !== null) {
            $query->where('latest_attempt.failure_type', $filter->failureType);
        }
        if ($filter->responseStatus !== null) {
            $query->where('latest_attempt.response_status', $filter->responseStatus);
        }
    }

    private function applyCursor(Builder $query, DeadLetterCursor $cursor): void
    {
        $query->where(function (Builder $query) use ($cursor): void {
            $query->where('latest_attempt.finished_at', '<', $cursor->toDatabaseValue())
                ->orWhere(function (Builder $query) use ($cursor): void {
                    $query->where('latest_attempt.finished_at', '=', $cursor->toDatabaseValue());
                    if (DB::connection()->getDriverName() === 'mysql') {
                        $query->whereRaw('BINARY deliveries.public_id < ?', [$cursor->deliveryId]);
                    } else {
                        $query->where('deliveries.public_id', '<', $cursor->deliveryId);
                    }
                });
        });
    }

    private function toItem(object $record): DeadLetterItem
    {
        return $this->toItemFromRow((array) $record);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function toItemFromRow(array $record): DeadLetterItem
    {
        $lastAttemptNumberValue = $this->required($record, 'last_attempt_number');
        if (! is_int($lastAttemptNumberValue) && ! ctype_digit((string) $lastAttemptNumberValue)) {
            throw new DeadLetterConsistencyViolation('A failed delivery must have a latest attempt.');
        }
        $attemptCountValue = $this->required($record, 'attempt_count');
        if (! is_int($attemptCountValue) && ! ctype_digit((string) $attemptCountValue)) {
            throw new DeadLetterConsistencyViolation('A failed delivery must have attempts.');
        }
        $status = $this->required($record, 'last_attempt_status');
        $failureType = $this->required($record, 'failure_type');
        $failedAt = $this->required($record, 'failed_at');
        if (! in_array($status, [DeliveryAttemptStatus::Failed->value, DeliveryAttemptStatus::Abandoned->value], true)
            || ! is_string($failureType)
            || DeliveryFailureType::tryFrom($failureType) === null
            || $failedAt === null) {
            throw new DeadLetterConsistencyViolation('A failed delivery has an invalid latest attempt.');
        }
        $responseStatus = $this->required($record, 'response_status');
        if ($responseStatus !== null
            && (! is_int($responseStatus) && ! ctype_digit((string) $responseStatus))) {
            throw new DeadLetterConsistencyViolation('A failed delivery has an invalid response status.');
        }

        $attemptCount = (int) $attemptCountValue;
        $lastAttemptNumber = (int) $lastAttemptNumberValue;
        if ($attemptCount < 1 || $lastAttemptNumber < 1 || $attemptCount < $lastAttemptNumber) {
            throw new DeadLetterConsistencyViolation('A failed delivery has inconsistent attempt numbering.');
        }

        $deliveryId = $this->requiredString($record, 'delivery_id');
        $eventId = $this->requiredString($record, 'event_id');
        $endpointId = $this->requiredString($record, 'endpoint_id');
        $eventType = $this->requiredString($record, 'event_type');
        $replayOf = $this->required($record, 'replay_of_delivery_id');
        if ($replayOf !== null && ! is_string($replayOf)) {
            throw new DeadLetterConsistencyViolation('A replay source identifier is invalid.');
        }

        return new DeadLetterItem(
            $deliveryId,
            $eventId,
            $endpointId,
            $replayOf,
            $eventType,
            $attemptCount,
            $lastAttemptNumber,
            $failureType,
            $responseStatus === null ? null : (int) $responseStatus,
            $this->date($failedAt),
            $this->date($this->required($record, 'created_at')),
        );
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function required(array $record, string $key): mixed
    {
        if (! array_key_exists($key, $record)) {
            throw new DeadLetterConsistencyViolation('A dead-letter query row is incomplete.');
        }

        return $record[$key];
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function requiredString(array $record, string $key): string
    {
        $value = $this->required($record, $key);
        if (! is_string($value)) {
            throw new DeadLetterConsistencyViolation('A dead-letter identifier is invalid.');
        }

        return $value;
    }

    private function date(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (! is_string($value)) {
            throw new DeadLetterConsistencyViolation('A dead-letter timestamp is invalid.');
        }

        $timezone = new DateTimeZone('UTC');
        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $value, $timezone)
            ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, $timezone);
        if ($date === false) {
            throw new DeadLetterConsistencyViolation('A dead-letter timestamp is invalid.');
        }

        return $date;
    }
}

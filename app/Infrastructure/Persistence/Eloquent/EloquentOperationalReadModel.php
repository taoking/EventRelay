<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\Delivery\RecoverStaleDelivery;
use App\Application\Operations\OperationalDataUnavailable;
use App\Application\Operations\OperationalReadinessRepository;
use App\Application\Operations\OperationalSnapshot;
use App\Application\Operations\OperationalSnapshotConsistencyViolation;
use App\Application\Operations\OperationalSnapshotRepository;
use App\Domain\Delivery\DeliveryStatus;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;

final class EloquentOperationalReadModel implements OperationalReadinessRepository, OperationalSnapshotRepository
{
    public function isMysqlAvailable(): bool
    {
        try {
            DB::select('SELECT 1');

            return true;
        } catch (PDOException|QueryException) {
            return false;
        }
    }

    public function collect(DateTimeImmutable $now): OperationalSnapshot
    {
        try {
            $deliveryCounts = $this->statusCounts(
                array_values(DeliveryRecord::query()->selectRaw('status, COUNT(*) AS metric_count')->groupBy('status')->get()->all()),
                array_map(static fn (DeliveryStatus $status): string => $status->value, DeliveryStatus::cases()),
            );
            $outboxCounts = $this->statusCounts(
                array_values(DeliveryOutboxMessageRecord::query()->selectRaw('status, COUNT(*) AS metric_count')->groupBy('status')->get()->all()),
                array_map(static fn (DeliveryOutboxMessageStatus $status): string => $status->value, DeliveryOutboxMessageStatus::cases()),
            );

            $due = EloquentDeliveryOutboxDueQuery::apply(DeliveryOutboxMessageRecord::query(), $now)
                ->selectRaw('COUNT(*) AS due_pending')
                ->selectRaw('MIN('.EloquentDeliveryOutboxDueQuery::EffectiveDueAtExpression.') AS oldest_due_at')
                ->first();
            $dueCount = $due === null ? 0 : $this->integer($due->getAttribute('due_pending'));
            $oldestDueAt = $due === null ? null : $due->getAttribute('oldest_due_at');

            $retryDue = EloquentDueRetryQuery::apply(DeliveryRecord::query(), $now)->count();
            $cutoff = $now->sub(new DateInterval('PT'.RecoverStaleDelivery::StaleThresholdSeconds.'S'));
            $staleCandidates = EloquentStaleDeliveryQuery::apply(DeliveryRecord::query(), $cutoff)->count();

            return new OperationalSnapshot(
                $deliveryCounts,
                $outboxCounts,
                $dueCount,
                $this->oldestDueAge($oldestDueAt, $now),
                $retryDue,
                $staleCandidates,
            );
        } catch (PDOException|QueryException $exception) {
            throw new OperationalDataUnavailable($exception);
        }
    }

    /**
     * @param  list<object>  $rows
     * @param  list<string>  $allowedStatuses
     * @return array<string, int>
     */
    private function statusCounts(array $rows, array $allowedStatuses): array
    {
        $counts = array_fill_keys($allowedStatuses, 0);

        foreach ($rows as $row) {
            if (! method_exists($row, 'getAttribute')) {
                throw new OperationalSnapshotConsistencyViolation('Operational aggregate rows must be model records.');
            }

            /** @var mixed $status */
            $status = $row->getAttribute('status');
            if (! is_string($status) || ! array_key_exists($status, $counts)) {
                throw new OperationalSnapshotConsistencyViolation('A persisted operational status is unknown.');
            }

            /** @var mixed $count */
            $count = $row->getAttribute('metric_count');
            $counts[$status] = $this->integer($count);
        }

        return $counts;
    }

    private function oldestDueAge(mixed $value, DateTimeImmutable $now): int
    {
        if ($value === null) {
            return 0;
        }

        if ($value instanceof DateTimeInterface) {
            $dueAt = DateTimeImmutable::createFromInterface($value);
        } elseif (is_string($value)) {
            $dueAt = new DateTimeImmutable($value);
        } else {
            throw new OperationalSnapshotConsistencyViolation('The oldest due outbox timestamp is invalid.');
        }

        return max(0, $now->getTimestamp() - $dueAt->getTimestamp());
    }

    private function integer(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new OperationalSnapshotConsistencyViolation('An operational aggregate count is invalid.');
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\Delivery\DeliveryRepository;
use App\Application\Endpoint\EndpointNotFound;
use App\Application\Event\EventNotFound;
use App\Domain\Delivery\Delivery;
use App\Domain\Delivery\DeliveryId;
use App\Domain\Delivery\DeliveryStatus;
use App\Domain\Endpoint\EndpointId;
use App\Domain\Event\EventId;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

final class EloquentDeliveryRepository implements DeliveryRepository
{
    public function createOrGet(Delivery $delivery): Delivery
    {
        return DB::transaction(function () use ($delivery): Delivery {
            $eventId = $this->internalEventId($delivery->eventId());
            $endpointId = $this->internalEndpointId($delivery->endpointId());

            try {
                $record = new DeliveryRecord;
                $record->fill([
                    'public_id' => $delivery->id()->toString(),
                    'event_id' => $eventId,
                    'endpoint_id' => $endpointId,
                    'target_url' => $delivery->targetUrl(),
                    'status' => $delivery->status()->value,
                    'created_at' => $delivery->createdAt(),
                    'updated_at' => $delivery->updatedAt(),
                ]);
                $record->save();

                return $this->toDomain($record->refresh());
            } catch (QueryException $exception) {
                if (! $this->isDeliveryPairUniqueViolation($exception)) {
                    throw $exception;
                }

                $existing = DeliveryRecord::query()
                    ->where('event_id', $eventId)
                    ->where('endpoint_id', $endpointId)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    return $this->toDomain($existing);
                }

                throw $exception;
            }
        });
    }

    public function all(): array
    {
        $deliveries = [];

        foreach (DeliveryRecord::query()->orderBy('id')->get() as $record) {
            $deliveries[] = $this->toDomain($record);
        }

        return $deliveries;
    }

    public function find(string $id): ?Delivery
    {
        $record = DeliveryRecord::query()
            ->where('public_id', $id)
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    private function internalEventId(EventId $eventId): int
    {
        $record = EventRecord::query()
            ->where('public_id', $eventId->toString())
            ->first();

        if ($record === null) {
            throw new EventNotFound($eventId->toString());
        }

        /** @var int $id */
        $id = $record->getKey();

        return $id;
    }

    private function internalEndpointId(EndpointId $endpointId): int
    {
        $record = EndpointRecord::query()
            ->lockForUpdate()
            ->where('public_id', $endpointId->toString())
            ->first();

        if ($record === null) {
            throw new EndpointNotFound($endpointId->toString());
        }

        /** @var int $id */
        $id = $record->getKey();

        return $id;
    }

    private function toDomain(DeliveryRecord $record): Delivery
    {
        if ($record->created_at === null || $record->updated_at === null || $record->target_url === null) {
            throw new LogicException('Persisted delivery fields are required.');
        }

        $eventId = $this->eventPublicId($record->event_id);
        $endpointId = $this->endpointPublicId($record->endpoint_id);

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

    private function eventPublicId(int $eventId): string
    {
        $record = EventRecord::query()->find($eventId);

        if ($record === null) {
            throw new EventNotFound((string) $eventId);
        }

        return $record->public_id;
    }

    private function endpointPublicId(int $endpointId): string
    {
        $record = EndpointRecord::query()
            ->withTrashed()
            ->find($endpointId);

        if ($record === null) {
            throw new EndpointNotFound((string) $endpointId);
        }

        return $record->public_id;
    }

    private function isDeliveryPairUniqueViolation(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'deliveries_event_id_endpoint_id_unique')
            || str_contains($message, 'UNIQUE constraint failed: deliveries.event_id, deliveries.endpoint_id');
    }
}

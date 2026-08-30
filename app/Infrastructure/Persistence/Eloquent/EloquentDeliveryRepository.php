<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\Delivery\DeliveryRepository;
use App\Application\Delivery\DeliverySnapshotCreator;
use App\Application\Endpoint\EndpointNotFound;
use App\Application\Event\EventNotFound;
use App\Domain\Delivery\Delivery;
use App\Domain\Delivery\DeliveryId;
use App\Domain\Delivery\DeliveryStatus;
use App\Domain\Endpoint\EndpointId;
use App\Domain\EndpointSigningSecret\EndpointSigningSecretId;
use App\Domain\Event\EventId;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

final class EloquentDeliveryRepository implements DeliveryRepository, DeliverySnapshotCreator
{
    /** Retained for the existing internal persistence contract. */
    public function createOrGet(Delivery $delivery): Delivery
    {
        return DB::transaction(function () use ($delivery): Delivery {
            return $this->persistOrGet(
                $delivery,
                $this->internalEventId($delivery->eventId()),
                $this->internalEndpointId($delivery->endpointId()),
                $delivery->targetUrl(),
                null,
            );
        });
    }

    public function createOrGetSnapshot(EventId $eventId, EndpointId $endpointId): Delivery
    {
        return DB::transaction(function () use ($eventId, $endpointId): Delivery {
            $internalEventId = $this->internalEventId($eventId);
            $endpoint = EndpointRecord::query()
                ->where('public_id', $endpointId->toString())
                ->lockForUpdate()
                ->first();

            if ($endpoint === null) {
                throw new EndpointNotFound($endpointId->toString());
            }

            $secretId = null;
            if ($endpoint->current_signing_secret_id !== null) {
                $secret = EndpointSigningSecretRecord::query()
                    ->whereKey($endpoint->current_signing_secret_id)
                    ->first();

                if ($secret === null || $secret->endpoint_id !== $endpoint->getKey()) {
                    throw new LogicException('Endpoint signing-secret pointer is corrupt.');
                }
                $secretId = EndpointSigningSecretId::fromString($secret->public_id);
            }

            $delivery = Delivery::create($eventId, $endpointId, $endpoint->url, $secretId);

            return $this->persistOrGet(
                $delivery,
                $internalEventId,
                (int) $endpoint->getKey(),
                $endpoint->url,
                $endpoint->current_signing_secret_id,
            );
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
        $record = DeliveryRecord::query()->where('public_id', $id)->first();

        return $record === null ? null : $this->toDomain($record);
    }

    private function persistOrGet(Delivery $delivery, int $eventId, int $endpointId, string $targetUrl, ?int $signingSecretId): Delivery
    {
        try {
            $record = new DeliveryRecord;
            $record->fill([
                'public_id' => $delivery->id()->toString(),
                'event_id' => $eventId,
                'endpoint_id' => $endpointId,
                'target_url' => $targetUrl,
                'signing_secret_id' => $signingSecretId,
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

            // 锁定读绕开 repeatable-read 的旧 consistent snapshot。
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
    }

    private function internalEventId(EventId $eventId): int
    {
        $record = EventRecord::query()->where('public_id', $eventId->toString())->first();
        if ($record === null) {
            throw new EventNotFound($eventId->toString());
        }

        return (int) $record->getKey();
    }

    private function internalEndpointId(EndpointId $endpointId): int
    {
        $record = EndpointRecord::query()->lockForUpdate()->where('public_id', $endpointId->toString())->first();
        if ($record === null) {
            throw new EndpointNotFound($endpointId->toString());
        }

        return (int) $record->getKey();
    }

    private function toDomain(DeliveryRecord $record): Delivery
    {
        if ($record->created_at === null || $record->updated_at === null || $record->target_url === null) {
            throw new LogicException('Persisted delivery fields are required.');
        }

        return Delivery::reconstitute(
            DeliveryId::fromString($record->public_id),
            EventId::fromString($this->eventPublicId($record->event_id)),
            EndpointId::fromString($this->endpointPublicId($record->endpoint_id)),
            $record->target_url,
            $record->signing_secret_id === null ? null : EndpointSigningSecretId::fromString($this->signingSecretPublicId($record->signing_secret_id)),
            DeliveryStatus::from($record->status),
            $record->created_at,
            $record->updated_at,
            $record->next_attempt_at,
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
        $record = EndpointRecord::query()->withTrashed()->find($endpointId);
        if ($record === null) {
            throw new EndpointNotFound((string) $endpointId);
        }

        return $record->public_id;
    }

    private function signingSecretPublicId(int $secretId): string
    {
        $record = EndpointSigningSecretRecord::query()->find($secretId);
        if ($record === null) {
            throw new LogicException('Persisted signing-secret reference is missing.');
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

<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\Delivery\DeliveryExecutionIntent;
use App\Application\Delivery\DeliveryNotFound;
use App\Application\Delivery\DeliveryNotReplayable;
use App\Application\Delivery\DeliveryOutboxWriter;
use App\Application\Delivery\DeliveryReplayCreator;
use App\Application\Delivery\DeliveryRepository;
use App\Application\Delivery\DeliverySnapshotCreator;
use App\Application\Delivery\ReplayDeliveryCreation;
use App\Application\Delivery\ReplayEndpointUnavailable;
use App\Application\Endpoint\EndpointNotFound;
use App\Application\Event\EventNotFound;
use App\Domain\Delivery\Delivery;
use App\Domain\Delivery\DeliveryId;
use App\Domain\Delivery\DeliveryStatus;
use App\Domain\Endpoint\EndpointId;
use App\Domain\Endpoint\EndpointStatus;
use App\Domain\EndpointSigningSecret\EndpointSigningSecretId;
use App\Domain\Event\EventId;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

final class EloquentDeliveryRepository implements DeliveryReplayCreator, DeliveryRepository, DeliverySnapshotCreator
{
    public function __construct(private readonly DeliveryOutboxWriter $outbox) {}

    /** Retained for the existing internal persistence contract. */
    public function createOrGet(Delivery $delivery): Delivery
    {
        return DB::transaction(function () use ($delivery): Delivery {
            $internalEventId = $this->internalEventId($delivery->eventId());
            $internalEndpointId = $this->internalEndpointId($delivery->endpointId());

            return $this->persistOrGet(
                $delivery,
                $internalEventId,
                $internalEndpointId,
                $delivery->targetUrl(),
                $this->internalSigningSecretId($delivery->signingSecretId(), $internalEndpointId),
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
                    ->lockForUpdate()
                    ->first();

                if ($secret === null || (int) $secret->endpoint_id !== (int) $endpoint->getKey()) {
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
                'creation_key' => 'primary',
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
                ->where('creation_key', 'primary')
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                return $this->toDomain($existing);
            }
            throw $exception;
        }
    }

    public function createReplay(DeliveryId $sourceDeliveryId, string $creationKey, \DateTimeImmutable $now): ReplayDeliveryCreation
    {
        return DB::transaction(function () use ($sourceDeliveryId, $creationKey, $now): ReplayDeliveryCreation {
            $source = DeliveryRecord::query()->where('public_id', $sourceDeliveryId->toString())->lockForUpdate()->first();
            if ($source === null) {
                throw new DeliveryNotFound($sourceDeliveryId->toString());
            }
            if ($source->status !== DeliveryStatus::Failed->value) {
                throw new DeliveryNotReplayable('Only failed deliveries can be replayed.');
            }
            $existing = DeliveryRecord::query()
                ->where('event_id', $source->event_id)
                ->where('endpoint_id', $source->endpoint_id)
                ->where('creation_key', $creationKey)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                return new ReplayDeliveryCreation($this->toDomain($existing), false);
            }

            $endpoint = EndpointRecord::query()->whereKey($source->endpoint_id)->lockForUpdate()->first();
            if ($endpoint === null || $endpoint->status !== EndpointStatus::Active->value) {
                throw new ReplayEndpointUnavailable('The replay endpoint is unavailable.');
            }

            $secretId = $this->currentSigningSecretId($endpoint);
            $eventId = EventId::fromString($this->eventPublicId($source->event_id));
            $endpointId = EndpointId::fromString($endpoint->public_id);
            $delivery = Delivery::replay($eventId, $endpointId, $endpoint->url, $sourceDeliveryId, $secretId === null ? null : EndpointSigningSecretId::fromString($this->signingSecretPublicId($secretId)));
            try {
                $record = new DeliveryRecord;
                $record->fill([
                    'public_id' => $delivery->id()->toString(), 'event_id' => $source->event_id, 'endpoint_id' => $endpoint->getKey(),
                    'creation_key' => $creationKey, 'replay_of_delivery_id' => $source->getKey(), 'target_url' => $endpoint->url,
                    'signing_secret_id' => $secretId, 'status' => DeliveryStatus::Pending->value, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $record->save();
            } catch (QueryException $exception) {
                if (! $this->isDeliveryCreationUniqueViolation($exception)) {
                    throw $exception;
                }
                $record = DeliveryRecord::query()->where('event_id', $source->event_id)->where('endpoint_id', $source->endpoint_id)->where('creation_key', $creationKey)->lockForUpdate()->first();
                if ($record === null) {
                    throw $exception;
                }

                return new ReplayDeliveryCreation($this->toDomain($record), false);
            }
            $this->outbox->schedule(new DeliveryExecutionIntent($delivery->id(), 1, null), $now);

            return new ReplayDeliveryCreation($this->toDomain($record->refresh()), true);
        });
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
            $record->replay_of_delivery_id === null ? null : DeliveryId::fromString($this->replaySourcePublicId($record->replay_of_delivery_id)),
            DeliveryStatus::from($record->status),
            $record->created_at,
            $record->updated_at,
            $record->next_attempt_at,
        );
    }

    private function replaySourcePublicId(int $internalId): string
    {
        $record = DeliveryRecord::query()->find($internalId);
        if ($record === null) {
            throw new LogicException('Persisted replay source is missing.');
        }

        return $record->public_id;
    }

    private function currentSigningSecretId(EndpointRecord $endpoint): ?int
    {
        if ($endpoint->current_signing_secret_id === null) {
            return null;
        }
        $secret = EndpointSigningSecretRecord::query()->whereKey($endpoint->current_signing_secret_id)->lockForUpdate()->first();
        if ($secret === null || (int) $secret->endpoint_id !== (int) $endpoint->getKey()) {
            throw new ReplayEndpointUnavailable('The replay endpoint signing configuration is unavailable.');
        }

        return (int) $secret->getKey();
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
        $record = EndpointSigningSecretRecord::query()
            ->lockForUpdate()
            ->find($secretId);
        if ($record === null) {
            throw new LogicException('Persisted signing-secret reference is missing.');
        }

        return $record->public_id;
    }

    private function internalSigningSecretId(?EndpointSigningSecretId $secretId, int $endpointId): ?int
    {
        if ($secretId === null) {
            return null;
        }

        $record = EndpointSigningSecretRecord::query()
            ->where('public_id', $secretId->toString())
            ->lockForUpdate()
            ->first();

        if ($record === null || (int) $record->endpoint_id !== $endpointId) {
            throw new LogicException('Delivery signing-secret reference is missing or belongs to another endpoint.');
        }

        return (int) $record->getKey();
    }

    private function isDeliveryPairUniqueViolation(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'deliveries_event_id_endpoint_id_creation_key_unique')
            || str_contains($message, 'UNIQUE constraint failed: deliveries.event_id, deliveries.endpoint_id, deliveries.creation_key');
    }

    private function isDeliveryCreationUniqueViolation(QueryException $exception): bool
    {
        return str_contains($exception->getMessage(), 'deliveries_event_id_endpoint_id_creation_key_unique')
            || str_contains($exception->getMessage(), 'UNIQUE constraint failed: deliveries.event_id, deliveries.endpoint_id, deliveries.creation_key');
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\Event\EventIngressIdempotency;
use App\Application\Event\EventIngressIdempotencyAlreadyExists;
use App\Application\Event\EventIngressIdempotencyRepository;
use App\Domain\Event\EventId;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use LogicException;

final class EloquentEventIngressIdempotencyRepository implements EventIngressIdempotencyRepository
{
    public function create(
        string $keyDigest,
        string $requestFingerprint,
        EventId $eventId,
        DateTimeImmutable $createdAt,
    ): void {
        $event = EventRecord::query()->where('public_id', $eventId->toString())->first();
        if ($event === null) {
            throw new LogicException('Event ingress idempotency requires a persisted event.');
        }

        try {
            $record = new EventIngressIdempotencyRecord;
            $record->fill([
                'key_digest' => $keyDigest,
                'request_fingerprint' => $requestFingerprint,
                'event_id' => $event->getKey(),
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
            $record->save();
        } catch (QueryException $exception) {
            if (! $this->isKeyDigestUniqueViolation($exception)) {
                throw $exception;
            }

            throw new EventIngressIdempotencyAlreadyExists('The event ingress idempotency key is already bound.', 0, $exception);
        }
    }

    public function findByKeyDigestForUpdate(string $keyDigest): ?EventIngressIdempotency
    {
        $record = EventIngressIdempotencyRecord::query()
            ->where('key_digest', $keyDigest)
            ->lockForUpdate()
            ->first();

        if ($record === null) {
            return null;
        }

        $event = EventRecord::query()->whereKey($record->event_id)->lockForUpdate()->first();
        if ($event === null) {
            throw new LogicException('Event ingress idempotency references a missing event.');
        }

        return new EventIngressIdempotency(
            $record->key_digest,
            $record->request_fingerprint,
            EventId::fromString($event->public_id),
        );
    }

    private function isKeyDigestUniqueViolation(QueryException $exception): bool
    {
        return str_contains($exception->getMessage(), 'event_ingress_idempotencies_key_digest_unique')
            || str_contains($exception->getMessage(), 'UNIQUE constraint failed: event_ingress_idempotencies.key_digest');
    }
}

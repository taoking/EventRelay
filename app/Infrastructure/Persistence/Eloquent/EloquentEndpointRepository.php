<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\Endpoint\EndpointRepository;
use App\Domain\Endpoint\Endpoint;
use App\Domain\Endpoint\EndpointId;
use App\Domain\Endpoint\EndpointStatus;
use LogicException;

final class EloquentEndpointRepository implements EndpointRepository
{
    public function save(Endpoint $endpoint): Endpoint
    {
        $record = EndpointRecord::query()->firstOrNew([
            'public_id' => $endpoint->id()->toString(),
        ]);

        $record->fill([
            'name' => $endpoint->name(),
            'url' => $endpoint->url(),
            'status' => $endpoint->status()->value,
        ]);
        $record->save();

        return $this->toDomain($record->refresh());
    }

    public function find(string $id): ?Endpoint
    {
        $record = EndpointRecord::query()
            ->where('public_id', $id)
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    public function delete(Endpoint $endpoint): void
    {
        EndpointRecord::query()
            ->where('public_id', $endpoint->id()->toString())
            ->firstOrFail()
            ->delete();
    }

    private function toDomain(EndpointRecord $record): Endpoint
    {
        if ($record->created_at === null || $record->updated_at === null) {
            throw new LogicException('Persisted endpoint timestamps are required.');
        }

        return Endpoint::reconstitute(
            EndpointId::fromString($record->public_id),
            $record->name,
            $record->url,
            EndpointStatus::from($record->status),
            $record->created_at,
            $record->updated_at,
        );
    }
}

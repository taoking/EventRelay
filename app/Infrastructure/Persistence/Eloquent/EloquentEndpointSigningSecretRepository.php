<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\Endpoint\EndpointNotFound;
use App\Application\EndpointSigningSecret\EndpointSigningSecretRepository;
use App\Application\EndpointSigningSecret\SecretCipher;
use App\Application\EndpointSigningSecret\SigningSecretNotFound;
use App\Domain\Endpoint\EndpointId;
use App\Domain\EndpointSigningSecret\EndpointSigningSecret;
use App\Domain\EndpointSigningSecret\EndpointSigningSecretId;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class EloquentEndpointSigningSecretRepository implements EndpointSigningSecretRepository
{
    public function __construct(
        private SecretCipher $cipher,
    ) {}

    public function rotate(string $endpointId, EndpointSigningSecretId $keyId, string $encryptedSecret, DateTimeImmutable $now): EndpointSigningSecret
    {
        return DB::transaction(function () use ($endpointId, $keyId, $encryptedSecret, $now): EndpointSigningSecret {
            $endpoint = EndpointRecord::query()->where('public_id', $endpointId)->lockForUpdate()->first();

            if ($endpoint === null) {
                throw new EndpointNotFound($endpointId);
            }

            $previous = null;
            if ($endpoint->current_signing_secret_id !== null) {
                $previous = EndpointSigningSecretRecord::query()
                    ->whereKey($endpoint->current_signing_secret_id)
                    ->lockForUpdate()
                    ->first();

                if ($previous === null || (int) $previous->endpoint_id !== (int) $endpoint->getKey()) {
                    throw new LogicException('Endpoint signing-secret pointer is corrupt.');
                }
            }

            $version = ((int) EndpointSigningSecretRecord::query()
                ->where('endpoint_id', $endpoint->getKey())
                ->max('version')) + 1;
            $record = new EndpointSigningSecretRecord;
            $record->fill([
                'public_id' => $keyId->toString(),
                'endpoint_id' => $endpoint->getKey(),
                'version' => $version,
                'encrypted_secret' => $encryptedSecret,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $record->save();

            if ($previous !== null) {
                $previous->fill(['retired_at' => $now, 'updated_at' => $now]);
                $previous->save();
            }

            $endpoint->fill(['current_signing_secret_id' => $record->getKey(), 'updated_at' => $now]);
            $endpoint->save();

            return $this->toDomain($record, $endpointId);
        });
    }

    public function plaintext(EndpointSigningSecretId $keyId): string
    {
        $record = EndpointSigningSecretRecord::query()->where('public_id', $keyId->toString())->first();

        if ($record === null) {
            throw new SigningSecretNotFound($keyId->toString());
        }

        return $this->cipher->decrypt($record->encrypted_secret);
    }

    private function toDomain(EndpointSigningSecretRecord $record, string $endpointId): EndpointSigningSecret
    {
        if ($record->created_at === null) {
            throw new LogicException('Persisted signing-secret creation timestamp is required.');
        }

        return EndpointSigningSecret::reconstitute(
            EndpointSigningSecretId::fromString($record->public_id),
            EndpointId::fromString($endpointId),
            $record->version,
            $record->created_at,
            $record->retired_at,
        );
    }
}

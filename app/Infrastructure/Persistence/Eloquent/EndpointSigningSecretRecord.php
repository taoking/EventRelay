<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $public_id
 * @property int $endpoint_id
 * @property int $version
 * @property string $encrypted_secret
 * @property \DateTimeInterface|null $retired_at
 * @property \DateTimeInterface|null $created_at
 * @property \DateTimeInterface|null $updated_at
 */
final class EndpointSigningSecretRecord extends Model
{
    protected $table = 'endpoint_signing_secrets';

    /** @var list<string> */
    protected $fillable = ['public_id', 'endpoint_id', 'version', 'encrypted_secret', 'retired_at', 'created_at', 'updated_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'retired_at' => 'immutable_datetime',
        ];
    }
}

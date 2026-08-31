<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key_digest
 * @property string $request_fingerprint
 * @property int $event_id
 * @property \DateTimeInterface|null $created_at
 * @property \DateTimeInterface|null $updated_at
 */
final class EventIngressIdempotencyRecord extends Model
{
    protected $table = 'event_ingress_idempotencies';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'key_digest',
        'request_fingerprint',
        'event_id',
        'created_at',
        'updated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}

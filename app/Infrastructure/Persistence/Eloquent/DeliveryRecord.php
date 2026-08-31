<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $public_id
 * @property int $event_id
 * @property int $endpoint_id
 * @property string $creation_key
 * @property int|null $replay_of_delivery_id
 * @property string|null $target_url
 * @property int|null $signing_secret_id
 * @property string $status
 * @property \DateTimeInterface|null $next_attempt_at
 * @property \DateTimeInterface|null $created_at
 * @property \DateTimeInterface|null $updated_at
 */
final class DeliveryRecord extends Model
{
    protected $table = 'deliveries';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'event_id',
        'endpoint_id',
        'creation_key',
        'replay_of_delivery_id',
        'target_url',
        'signing_secret_id',
        'status',
        'next_attempt_at',
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
            'next_attempt_at' => 'immutable_datetime',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $public_id
 * @property int $event_id
 * @property int $endpoint_id
 * @property string|null $target_url
 * @property string $status
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
        'target_url',
        'status',
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

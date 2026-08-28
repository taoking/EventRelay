<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $public_id
 * @property string $type
 * @property \stdClass $payload
 * @property \DateTimeInterface|null $created_at
 */
final class EventRecord extends Model
{
    public $timestamps = false;

    protected $table = 'events';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'type',
        'payload',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'object',
            'created_at' => 'immutable_datetime',
        ];
    }
}

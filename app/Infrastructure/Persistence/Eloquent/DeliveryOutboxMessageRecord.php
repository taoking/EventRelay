<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $public_id
 * @property int $delivery_id
 * @property string $message_type
 * @property string $dedupe_key
 * @property int $attempt_number
 * @property \DateTimeInterface|null $available_at
 * @property string $status
 * @property string|null $claim_token
 * @property \DateTimeInterface|null $claimed_until
 * @property int $publication_attempts
 * @property string|null $last_error_code
 * @property \DateTimeInterface|null $published_at
 * @property \DateTimeInterface|null $created_at
 * @property \DateTimeInterface|null $updated_at
 */
final class DeliveryOutboxMessageRecord extends Model
{
    protected $table = 'delivery_outbox_messages';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'delivery_id',
        'message_type',
        'dedupe_key',
        'attempt_number',
        'available_at',
        'status',
        'claim_token',
        'claimed_until',
        'publication_attempts',
        'last_error_code',
        'published_at',
        'created_at',
        'updated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'available_at' => 'immutable_datetime',
            'claimed_until' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}

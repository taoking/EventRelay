<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $public_id
 * @property int $delivery_id
 * @property int $attempt_number
 * @property string $status
 * @property int|null $response_status
 * @property string|null $failure_type
 * @property string|null $failure_message
 * @property int|null $duration_ms
 * @property \DateTimeInterface $started_at
 * @property \DateTimeInterface|null $finished_at
 */
final class DeliveryAttemptRecord extends Model
{
    protected $table = 'delivery_attempts';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id', 'delivery_id', 'attempt_number', 'status', 'response_status',
        'failure_type', 'failure_message', 'duration_ms', 'started_at', 'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}

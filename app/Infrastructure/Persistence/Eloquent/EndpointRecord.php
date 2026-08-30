<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $public_id
 * @property string $name
 * @property string $url
 * @property string $status
 * @property int|null $current_signing_secret_id
 * @property \DateTimeInterface|null $created_at
 * @property \DateTimeInterface|null $updated_at
 */
final class EndpointRecord extends Model
{
    use SoftDeletes;

    protected $table = 'endpoints';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'name',
        'url',
        'status',
        'current_signing_secret_id',
    ];
}

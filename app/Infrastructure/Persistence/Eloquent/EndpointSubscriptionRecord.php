<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $endpoint_id
 * @property string $event_type
 */
final class EndpointSubscriptionRecord extends Model
{
    protected $table = 'endpoint_subscriptions';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'endpoint_id',
        'event_type',
    ];
}

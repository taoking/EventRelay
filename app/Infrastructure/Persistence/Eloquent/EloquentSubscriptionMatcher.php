<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\Subscription\SubscriptionMatcher;
use App\Domain\Endpoint\EndpointId;
use App\Domain\Endpoint\EndpointStatus;
use App\Domain\Event\EventType;
use Illuminate\Support\Facades\DB;
use LogicException;

final class EloquentSubscriptionMatcher implements SubscriptionMatcher
{
    public function matchingActiveEndpointIds(EventType $eventType): array
    {
        $records = DB::table('endpoint_subscriptions')
            ->join('endpoints', 'endpoint_subscriptions.endpoint_id', '=', 'endpoints.id')
            ->select('endpoints.public_id')
            ->where('endpoint_subscriptions.event_type', $eventType->toString())
            ->where('endpoints.status', EndpointStatus::Active->value)
            ->whereNull('endpoints.deleted_at')
            ->orderBy('endpoints.id')
            ->lockForUpdate()
            ->get();

        /** @var array<string, EndpointId> $endpointIds */
        $endpointIds = [];

        foreach ($records as $record) {
            if (! is_string($record->public_id)) {
                throw new LogicException('Matched endpoint public IDs must be strings.');
            }

            $endpointIds[$record->public_id] = EndpointId::fromString($record->public_id);
        }

        return array_values($endpointIds);
    }
}

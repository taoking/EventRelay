<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\Subscription\EndpointSubscriptionRepository;
use App\Domain\Endpoint\EndpointId;
use App\Domain\Subscription\EndpointSubscriptions;
use Illuminate\Support\Facades\DB;
use LogicException;

final class EloquentEndpointSubscriptionRepository implements EndpointSubscriptionRepository
{
    public function forEndpoint(EndpointId $endpointId): EndpointSubscriptions
    {
        $persistedTypes = EndpointSubscriptionRecord::query()
            ->where('endpoint_id', $this->internalEndpointId($endpointId))
            ->orderBy('event_type')
            ->pluck('event_type')
            ->all();

        $types = [];

        foreach ($persistedTypes as $type) {
            if (! is_string($type)) {
                throw new LogicException('Persisted subscription event types must be strings.');
            }

            $types[] = $type;
        }

        return EndpointSubscriptions::replace($endpointId, $types);
    }

    public function replace(EndpointSubscriptions $subscriptions): void
    {
        DB::transaction(function () use ($subscriptions): void {
            $endpointId = $this->internalEndpointId($subscriptions->endpointId());

            EndpointSubscriptionRecord::query()
                ->where('endpoint_id', $endpointId)
                ->delete();

            foreach ($subscriptions->types() as $type) {
                EndpointSubscriptionRecord::query()->create([
                    'endpoint_id' => $endpointId,
                    'event_type' => $type->toString(),
                ]);
            }
        });
    }

    private function internalEndpointId(EndpointId $endpointId): int
    {
        /** @var int $id */
        $id = EndpointRecord::query()
            ->where('public_id', $endpointId->toString())
            ->firstOrFail()
            ->getKey();

        return $id;
    }
}

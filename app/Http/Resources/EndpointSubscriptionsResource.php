<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Application\Subscription\EndpointSubscriptionData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EndpointSubscriptionData
 */
final class EndpointSubscriptionsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var EndpointSubscriptionData $subscriptions */
        $subscriptions = $this->resource;

        return [
            'endpoint_id' => $subscriptions->endpointId,
            'types' => $subscriptions->types,
        ];
    }
}

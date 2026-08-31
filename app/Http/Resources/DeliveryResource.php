<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Application\Delivery\DeliveryData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DeliveryData
 */
final class DeliveryResource extends JsonResource
{
    /**
     * @return array<string, string|null>
     */
    public function toArray(Request $request): array
    {
        /** @var DeliveryData $delivery */
        $delivery = $this->resource;

        return [
            'id' => $delivery->id,
            'event_id' => $delivery->eventId,
            'endpoint_id' => $delivery->endpointId,
            'replay_of_delivery_id' => $delivery->replayOfDeliveryId,
            'status' => $delivery->status,
            'created_at' => $delivery->createdAt,
            'updated_at' => $delivery->updatedAt,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Application\DeadLetter\DeadLetterItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DeadLetterItem
 */
final class DeadLetterResource extends JsonResource
{
    /**
     * @return array<string, int|string|null>
     */
    public function toArray(Request $request): array
    {
        /** @var DeadLetterItem $item */
        $item = $this->resource;

        return [
            'delivery_id' => $item->deliveryId,
            'event_id' => $item->eventId,
            'endpoint_id' => $item->endpointId,
            'replay_of_delivery_id' => $item->replayOfDeliveryId,
            'event_type' => $item->eventType,
            'attempt_count' => $item->attemptCount,
            'last_attempt_number' => $item->lastAttemptNumber,
            'failure_type' => $item->failureType,
            'response_status' => $item->responseStatus,
            'failed_at' => $item->failedAt->format(DATE_ATOM),
            'created_at' => $item->createdAt->format(DATE_ATOM),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Application\Delivery\DeliveryAttemptData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DeliveryAttemptData
 */
final class DeliveryAttemptResource extends JsonResource
{
    /**
     * @return array<string, int|string|null>
     */
    public function toArray(Request $request): array
    {
        /** @var DeliveryAttemptData $attempt */
        $attempt = $this->resource;

        return [
            'id' => $attempt->id,
            'attempt_number' => $attempt->attemptNumber,
            'status' => $attempt->status,
            'response_status' => $attempt->responseStatus,
            'failure_type' => $attempt->failureType,
            'failure_message' => $attempt->failureMessage,
            'duration_ms' => $attempt->durationMs,
            'started_at' => $attempt->startedAt,
            'finished_at' => $attempt->finishedAt,
        ];
    }
}

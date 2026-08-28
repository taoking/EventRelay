<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Application\Event\EventData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EventData
 */
final class EventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var EventData $event */
        $event = $this->resource;

        return [
            'id' => $event->id,
            'type' => $event->type,
            'payload' => $event->payload,
            'created_at' => $event->createdAt,
        ];
    }
}

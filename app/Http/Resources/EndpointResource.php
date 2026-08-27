<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Application\Endpoint\EndpointData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EndpointData
 */
final class EndpointResource extends JsonResource
{
    /**
     * @return array<string, string>
     */
    public function toArray(Request $request): array
    {
        /** @var EndpointData $endpoint */
        $endpoint = $this->resource;

        return [
            'id' => $endpoint->id,
            'name' => $endpoint->name,
            'url' => $endpoint->url,
            'status' => $endpoint->status,
            'created_at' => $endpoint->createdAt,
            'updated_at' => $endpoint->updatedAt,
        ];
    }
}

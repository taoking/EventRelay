<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\CoreList\CoreListCursor;
use App\Application\CoreList\CoreListCursorCodec;
use App\Application\CoreList\CoreListPage;
use App\Application\CoreList\CoreListPageRequest;
use App\Application\CoreList\CoreListResource;
use App\Application\Endpoint\EndpointData;
use App\Application\Endpoint\EndpointPageRepository;
use App\Domain\Endpoint\Endpoint;
use App\Domain\Endpoint\EndpointId;
use App\Domain\Endpoint\EndpointStatus;
use LogicException;

final readonly class EloquentEndpointPageRepository implements EndpointPageRepository
{
    public function __construct(private CoreListCursorCodec $cursors) {}

    public function page(CoreListPageRequest $request): CoreListPage
    {
        $cursor = $request->cursor === null ? null : $this->cursors->decode($request->cursor, CoreListResource::Endpoints);
        $upperKey = $cursor === null ? $this->upperKey() : $cursor->upperKey;
        if ($upperKey === null) {
            return new CoreListPage([], $request->limit, null);
        }

        $query = EndpointRecord::query()
            ->where('id', '<=', $upperKey)
            ->orderBy('id')
            ->limit($request->limit + 1);
        if ($cursor !== null) {
            $query->where('id', '>', $cursor->afterKey);
        }

        $records = $query->get();
        $hasMore = $records->count() > $request->limit;
        $returned = $records->take($request->limit)->values();
        $nextCursor = null;
        if ($hasMore) {
            /** @var EndpointRecord $last */
            $last = $returned->last();
            $nextCursor = $this->cursors->encode(new CoreListCursor(
                CoreListResource::Endpoints,
                (int) $last->getKey(),
                $upperKey,
            ));
        }

        $items = [];
        foreach ($returned as $record) {
            $items[] = $this->toData($record);
        }

        return new CoreListPage($items, $request->limit, $nextCursor);
    }

    private function upperKey(): ?int
    {
        $value = EndpointRecord::query()->max('id');

        return $value === null ? null : (int) $value;
    }

    private function toData(EndpointRecord $record): EndpointData
    {
        if ($record->created_at === null || $record->updated_at === null) {
            throw new LogicException('Persisted endpoint timestamps are required.');
        }

        return EndpointData::fromDomain(Endpoint::reconstitute(
            EndpointId::fromString($record->public_id),
            $record->name,
            $record->url,
            EndpointStatus::from($record->status),
            $record->created_at,
            $record->updated_at,
        ));
    }
}

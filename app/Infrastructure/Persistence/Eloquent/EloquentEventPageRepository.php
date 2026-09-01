<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\CoreList\CoreListPage;
use App\Application\CoreList\CoreListPageRequest;
use App\Application\Event\EventData;
use App\Application\Event\EventPageRepository;
use App\Domain\Event\Event;
use App\Domain\Event\EventId;
use App\Infrastructure\CoreList\CoreListCursor;
use App\Infrastructure\CoreList\CoreListResource;
use App\Infrastructure\CoreList\LaravelCoreListCursorCodec;
use LogicException;

final readonly class EloquentEventPageRepository implements EventPageRepository
{
    public function __construct(private LaravelCoreListCursorCodec $cursors) {}

    public function page(CoreListPageRequest $request): CoreListPage
    {
        $cursor = $request->cursor === null ? null : $this->cursors->decode($request->cursor, CoreListResource::Events);
        $upperKey = $cursor === null ? $this->upperKey() : $cursor->upperKey;
        if ($upperKey === null) {
            return new CoreListPage([], $request->limit, null);
        }

        $query = EventRecord::query()
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
            /** @var EventRecord $last */
            $last = $returned->last();
            $nextCursor = $this->cursors->encode(new CoreListCursor(
                CoreListResource::Events,
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
        $value = EventRecord::query()->max('id');

        return $value === null ? null : (int) $value;
    }

    private function toData(EventRecord $record): EventData
    {
        if ($record->created_at === null) {
            throw new LogicException('Persisted event creation time is required.');
        }

        return EventData::fromDomain(Event::reconstitute(
            EventId::fromString($record->public_id),
            $record->type,
            $record->payload,
            $record->created_at,
        ));
    }
}

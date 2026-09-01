<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\CoreList\CoreListPage;
use App\Application\CoreList\CoreListPageRequest;
use App\Application\Delivery\DeliveryData;
use App\Application\Delivery\DeliveryPageRepository;
use App\Infrastructure\CoreList\CoreListCursor;
use App\Infrastructure\CoreList\CoreListResource;
use App\Infrastructure\CoreList\LaravelCoreListCursorCodec;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class EloquentDeliveryPageRepository implements DeliveryPageRepository
{
    public function __construct(private LaravelCoreListCursorCodec $cursors) {}

    public function page(CoreListPageRequest $request): CoreListPage
    {
        $cursor = $request->cursor === null ? null : $this->cursors->decode($request->cursor, CoreListResource::Deliveries);
        $upperKey = $cursor === null ? $this->upperKey() : $cursor->upperKey;
        if ($upperKey === null) {
            return new CoreListPage([], $request->limit, null);
        }

        $query = $this->baseQuery()
            ->where('deliveries.id', '<=', $upperKey)
            ->orderBy('deliveries.id')
            ->limit($request->limit + 1);
        if ($cursor !== null) {
            $query->where('deliveries.id', '>', $cursor->afterKey);
        }

        $records = $query->get();
        $hasMore = $records->count() > $request->limit;
        $returned = $records->take($request->limit)->values();
        $nextCursor = null;
        if ($hasMore) {
            /** @var object $last */
            $last = $returned->last();
            $nextCursor = $this->cursors->encode(new CoreListCursor(
                CoreListResource::Deliveries,
                $this->integer($last, 'cursor_key'),
                $upperKey,
            ));
        }

        $items = [];
        foreach ($returned as $record) {
            $items[] = $this->toData($record);
        }

        return new CoreListPage($items, $request->limit, $nextCursor);
    }

    private function baseQuery(): Builder
    {
        return DB::table('deliveries as deliveries')
            ->select([
                'deliveries.id as cursor_key',
                'deliveries.public_id as delivery_id',
                'events.public_id as event_id',
                'endpoints.public_id as endpoint_id',
                'replay_source.public_id as replay_of_delivery_id',
                'deliveries.target_url',
                'deliveries.status',
                'deliveries.created_at',
                'deliveries.updated_at',
            ])
            ->join('events', 'events.id', '=', 'deliveries.event_id')
            ->join('endpoints', 'endpoints.id', '=', 'deliveries.endpoint_id')
            ->leftJoin('deliveries as replay_source', 'replay_source.id', '=', 'deliveries.replay_of_delivery_id');
    }

    private function upperKey(): ?int
    {
        $value = DeliveryRecord::query()->max('id');

        return $value === null ? null : (int) $value;
    }

    private function toData(object $record): DeliveryData
    {
        $replayOf = $this->value($record, 'replay_of_delivery_id');
        if ($replayOf !== null && ! is_string($replayOf)) {
            throw new LogicException('Persisted replay source identifier is invalid.');
        }

        return new DeliveryData(
            $this->string($record, 'delivery_id'),
            $this->string($record, 'event_id'),
            $this->string($record, 'endpoint_id'),
            $this->string($record, 'target_url'),
            $replayOf,
            $this->string($record, 'status'),
            $this->atom($this->value($record, 'created_at')),
            $this->atom($this->value($record, 'updated_at')),
        );
    }

    private function string(object $record, string $property): string
    {
        $value = $this->value($record, $property);
        if (! is_string($value)) {
            throw new LogicException("Persisted delivery {$property} is required.");
        }

        return $value;
    }

    private function integer(object $record, string $property): int
    {
        $value = $this->value($record, $property);
        if (! is_int($value) && ! ctype_digit((string) $value)) {
            throw new LogicException("Persisted delivery {$property} is invalid.");
        }

        return (int) $value;
    }

    private function atom(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->format(DATE_ATOM);
        }
        if (! is_string($value)) {
            throw new LogicException('Persisted delivery timestamp is required.');
        }

        return new DateTimeImmutable($value, new DateTimeZone('UTC'))->format(DATE_ATOM);
    }

    private function value(object $record, string $property): mixed
    {
        if (! property_exists($record, $property)) {
            throw new LogicException("Persisted delivery {$property} is required.");
        }

        return $record->{$property};
    }
}

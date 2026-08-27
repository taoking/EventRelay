<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\Event\EventRepository;
use App\Domain\Event\Event;
use App\Domain\Event\EventId;
use LogicException;

final class EloquentEventRepository implements EventRepository
{
    public function save(Event $event): Event
    {
        $record = new EventRecord;
        $record->fill([
            'public_id' => $event->id()->toString(),
            'type' => $event->type(),
            'payload' => $event->payloadObject(),
            'created_at' => $event->createdAt(),
        ]);
        $record->save();

        return $this->toDomain($record->refresh());
    }

    public function all(): array
    {
        $events = [];

        foreach (EventRecord::query()->orderBy('id')->get() as $record) {
            $events[] = $this->toDomain($record);
        }

        return $events;
    }

    public function find(string $id): ?Event
    {
        $record = EventRecord::query()
            ->where('public_id', $id)
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    private function toDomain(EventRecord $record): Event
    {
        if ($record->created_at === null) {
            throw new LogicException('Persisted event creation time is required.');
        }

        return Event::reconstitute(
            EventId::fromString($record->public_id),
            $record->type,
            $record->payload,
            $record->created_at,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Application\DeadLetter;

final readonly class ListDeadLetters
{
    public function __construct(
        private DeadLetterQueryRepository $deadLetters,
        private DeadLetterCursorCodec $cursors,
    ) {}

    public function handle(DeadLetterFilter $filter, ?string $encodedCursor): DeadLetterPage
    {
        $cursor = $encodedCursor === null ? null : $this->cursors->decode($encodedCursor, $filter);
        $items = $this->deadLetters->find($filter, $cursor);
        $hasMore = count($items) > $filter->limit;
        $items = array_slice($items, 0, $filter->limit);

        $nextCursor = null;
        if ($hasMore && $items !== []) {
            $last = $items[count($items) - 1];
            $nextCursor = $this->cursors->encode(DeadLetterCursor::fromItem($last), $filter);
        }

        return new DeadLetterPage($items, $nextCursor);
    }
}

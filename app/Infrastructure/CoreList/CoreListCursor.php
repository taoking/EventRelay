<?php

declare(strict_types=1);

namespace App\Infrastructure\CoreList;

use App\Application\CoreList\InvalidPaginationCursor;

final readonly class CoreListCursor
{
    public function __construct(
        public CoreListResource $resource,
        public int $afterKey,
        public int $upperKey,
    ) {
        if ($afterKey < 1 || $upperKey < 1 || $afterKey >= $upperKey) {
            throw new InvalidPaginationCursor('Pagination cursor keyset is invalid.');
        }
    }

    public static function fromStorage(CoreListResource $resource, mixed $afterKey, mixed $upperKey): self
    {
        if (! is_int($afterKey) || ! is_int($upperKey)) {
            throw new InvalidPaginationCursor('Pagination cursor keyset is invalid.');
        }

        return new self($resource, $afterKey, $upperKey);
    }
}

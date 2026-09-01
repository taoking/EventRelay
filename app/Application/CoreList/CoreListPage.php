<?php

declare(strict_types=1);

namespace App\Application\CoreList;

final readonly class CoreListPage
{
    /**
     * @param  list<mixed>  $items
     */
    public function __construct(
        public array $items,
        public int $limit,
        public ?string $nextCursor,
    ) {}
}

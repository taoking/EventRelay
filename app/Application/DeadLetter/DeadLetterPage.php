<?php

declare(strict_types=1);

namespace App\Application\DeadLetter;

final readonly class DeadLetterPage
{
    /**
     * @param  list<DeadLetterItem>  $items
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {}
}

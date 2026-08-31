<?php

declare(strict_types=1);

namespace App\Application\DeadLetter;

interface DeadLetterCursorCodec
{
    public function encode(DeadLetterCursor $cursor, DeadLetterFilter $filter): string;

    public function decode(string $cursor, DeadLetterFilter $filter): DeadLetterCursor;
}

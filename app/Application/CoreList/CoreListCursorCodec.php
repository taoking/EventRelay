<?php

declare(strict_types=1);

namespace App\Application\CoreList;

interface CoreListCursorCodec
{
    public function encode(CoreListCursor $cursor): string;

    public function decode(string $cursor, CoreListResource $resource): CoreListCursor;
}

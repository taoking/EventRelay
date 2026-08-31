<?php

declare(strict_types=1);

namespace App\Application\DeadLetter;

interface DeadLetterQueryRepository
{
    /**
     * 返回最多 limit + 1 条，以便 Application 生成 keyset next cursor。
     *
     * @return list<DeadLetterItem>
     */
    public function find(DeadLetterFilter $filter, ?DeadLetterCursor $cursor): array;
}

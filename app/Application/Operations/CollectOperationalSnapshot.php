<?php

declare(strict_types=1);

namespace App\Application\Operations;

use App\Application\Clock\Clock;

final readonly class CollectOperationalSnapshot
{
    public function __construct(
        private OperationalSnapshotRepository $snapshots,
        private Clock $clock,
    ) {}

    public function handle(): OperationalSnapshot
    {
        return $this->snapshots->collect($this->clock->now());
    }
}

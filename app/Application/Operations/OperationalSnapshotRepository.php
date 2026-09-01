<?php

declare(strict_types=1);

namespace App\Application\Operations;

use DateTimeImmutable;

interface OperationalSnapshotRepository
{
    public function collect(DateTimeImmutable $now): OperationalSnapshot;
}

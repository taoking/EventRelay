<?php

declare(strict_types=1);

namespace App\Application\Operations;

interface OperationalMetricsRenderer
{
    public function contentType(): string;

    public function render(OperationalSnapshot $snapshot, string $transport): string;
}

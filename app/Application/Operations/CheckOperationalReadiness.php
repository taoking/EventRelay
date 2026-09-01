<?php

declare(strict_types=1);

namespace App\Application\Operations;

final readonly class CheckOperationalReadiness
{
    public function __construct(private OperationalReadinessRepository $readiness) {}

    public function mysqlIsAvailable(): bool
    {
        return $this->readiness->isMysqlAvailable();
    }
}

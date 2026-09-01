<?php

declare(strict_types=1);

namespace App\Application\Operations;

interface OperationalReadinessRepository
{
    /**
     * 只检查 MySQL 是否可以完成最小 durable write/commit 探测。
     */
    public function isMysqlAvailable(): bool;
}

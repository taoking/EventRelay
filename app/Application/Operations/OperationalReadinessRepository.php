<?php

declare(strict_types=1);

namespace App\Application\Operations;

interface OperationalReadinessRepository
{
    /**
     * 只检查 MySQL 是否可以执行最小的 durable read/write 基础探测。
     */
    public function isMysqlAvailable(): bool;
}

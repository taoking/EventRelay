<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use DateTimeImmutable;

/**
 * 根据当前 MySQL 业务状态修复已丢失的 Redis execution request。
 *
 * 与业务 transaction 内的 schedule() 分离：这里可以将已 published、
 * 但尚未开始的 execution intent 重新置为 pending。
 */
interface DeliveryOutboxRecovery
{
    /**
     * 仅当 intent 仍是当前尚未开始的业务 execution 时才确保其可发布。
     *
     * 返回 false 表示 Delivery 状态已经变化，不能安全 re-arm。
     */
    public function ensureRecoverable(DeliveryExecutionIntent $intent, DateTimeImmutable $now): bool;
}

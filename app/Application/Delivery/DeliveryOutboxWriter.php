<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use DateTimeImmutable;

/**
 * 在调用方已开启的业务事务中持久化 Delivery 执行意图。
 */
interface DeliveryOutboxWriter
{
    public function schedule(DeliveryExecutionIntent $intent, DateTimeImmutable $now): void;
}

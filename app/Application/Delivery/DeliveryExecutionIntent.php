<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Domain\Delivery\DeliveryId;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * 一次 Delivery 业务执行请求的稳定身份。
 *
 * Outbox 和 Redis 都只携带 Delivery UUID；attempt number 仅用于 durable intent 去重。
 */
final readonly class DeliveryExecutionIntent
{
    public const string MessageType = 'delivery.process';

    public function __construct(
        public DeliveryId $deliveryId,
        public int $attemptNumber,
        public ?DateTimeImmutable $availableAt,
    ) {
        if ($attemptNumber < 1) {
            throw new InvalidArgumentException('Delivery execution intent attempt number must be at least one.');
        }
    }

    public function dedupeKey(): string
    {
        return sprintf('delivery:%s:attempt:%d', $this->deliveryId->toString(), $this->attemptNumber);
    }
}

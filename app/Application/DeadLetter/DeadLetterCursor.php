<?php

declare(strict_types=1);

namespace App\Application\DeadLetter;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

final readonly class DeadLetterCursor
{
    public function __construct(
        public DateTimeImmutable $failedAt,
        public string $deliveryId,
    ) {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $deliveryId) !== 1) {
            throw new InvalidArgumentException('Delivery id must be a UUID v4.');
        }
    }

    public static function fromItem(DeadLetterItem $item): self
    {
        return new self($item->failedAt, $item->deliveryId);
    }

    public static function fromStorage(string $failedAt, string $deliveryId): self
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d\\TH:i:s.u\\Z', $failedAt, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new InvalidArgumentException('Cursor failed_at is invalid.');
        }

        return new self($date, $deliveryId);
    }

    public function toStorage(): string
    {
        return $this->failedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z');
    }

    public function toDatabaseValue(): string
    {
        // 现有 DeliveryAttempt migration 使用无精度 timestamp；游标查询保持同一秒精度，
        // UUID binary tie-break 负责同秒内的确定顺序。
        return $this->failedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function immutable(DateTimeInterface $date): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($date);
    }
}

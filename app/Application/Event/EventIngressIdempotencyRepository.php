<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Domain\Event\EventId;
use DateTimeImmutable;

interface EventIngressIdempotencyRepository
{
    /** 在调用方已有的业务 transaction 内写入 binding。 */
    public function create(
        string $keyDigest,
        string $requestFingerprint,
        EventId $eventId,
        DateTimeImmutable $createdAt,
    ): void;

    /** 使用 locking current read 恢复已提交的 unique-constraint winner。 */
    public function findByKeyDigestForUpdate(string $keyDigest): ?EventIngressIdempotency;
}

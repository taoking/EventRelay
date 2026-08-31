<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Domain\Delivery\DeliveryId;
use DateTimeImmutable;

interface DeliveryReplayCreator
{
    /** 在单个 MySQL transaction 内创建 replay Delivery 与 initial Outbox intent。 */
    public function createReplay(DeliveryId $sourceDeliveryId, string $creationKey, DateTimeImmutable $now): ReplayDeliveryCreation;
}

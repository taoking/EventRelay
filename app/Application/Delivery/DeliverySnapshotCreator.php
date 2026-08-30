<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Domain\Delivery\Delivery;
use App\Domain\Endpoint\EndpointId;
use App\Domain\Event\EventId;

interface DeliverySnapshotCreator
{
    /** 在同一端点锁窗口冻结 target URL 与 signing key。 */
    public function createOrGetSnapshot(EventId $eventId, EndpointId $endpointId): Delivery;
}

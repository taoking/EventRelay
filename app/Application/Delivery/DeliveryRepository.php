<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Domain\Delivery\Delivery;

interface DeliveryRepository
{
    public function createOrGet(Delivery $delivery): Delivery;

    /**
     * @return list<Delivery>
     */
    public function all(): array;

    public function find(string $id): ?Delivery;
}

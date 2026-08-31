<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Domain\Delivery\Delivery;

final readonly class ReplayDeliveryCreation
{
    public function __construct(public Delivery $delivery, public bool $created) {}
}

<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use RuntimeException;

final class DeliveryNotFound extends RuntimeException
{
    public function __construct(string $id)
    {
        parent::__construct(sprintf('Delivery "%s" was not found.', $id));
    }
}

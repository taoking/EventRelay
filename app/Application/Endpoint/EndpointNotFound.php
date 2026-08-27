<?php

declare(strict_types=1);

namespace App\Application\Endpoint;

use RuntimeException;

final class EndpointNotFound extends RuntimeException
{
    public function __construct(string $id)
    {
        parent::__construct(sprintf('Endpoint "%s" was not found.', $id));
    }
}

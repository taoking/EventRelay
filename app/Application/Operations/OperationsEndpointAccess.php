<?php

declare(strict_types=1);

namespace App\Application\Operations;

interface OperationsEndpointAccess
{
    public function isEnabled(): bool;

    public function accepts(?string $token): bool;
}

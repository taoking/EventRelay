<?php

declare(strict_types=1);

namespace Runtime;

final class Cancellation
{
    private bool $requested = false;

    public function request(): void
    {
        $this->requested = true;
    }

    public function requested(): bool
    {
        return $this->requested;
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Delivery;

final readonly class RetryDecision
{
    private function __construct(
        public bool $shouldRetry,
        public ?int $delaySeconds,
    ) {}

    public static function retryAfter(int $delaySeconds): self
    {
        if ($delaySeconds < 1) {
            throw new \LogicException('Retry delay must be positive.');
        }

        return new self(true, $delaySeconds);
    }

    public static function terminal(): self
    {
        return new self(false, null);
    }
}

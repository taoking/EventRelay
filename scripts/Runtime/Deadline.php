<?php

declare(strict_types=1);

namespace Runtime;

final readonly class Deadline
{
    private function __construct(private int $endsAtNanoseconds) {}

    public static function afterSeconds(float $seconds): self
    {
        if ($seconds <= 0) {
            throw new RuntimeException('Deadline seconds must be positive.');
        }

        return new self(hrtime(true) + (int) round($seconds * 1_000_000_000));
    }

    public function expired(): bool
    {
        return hrtime(true) >= $this->endsAtNanoseconds;
    }

    public function remainingMicroseconds(): int
    {
        return max(0, (int) floor(($this->endsAtNanoseconds - hrtime(true)) / 1_000));
    }
}

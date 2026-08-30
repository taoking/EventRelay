<?php

declare(strict_types=1);

namespace App\Domain\DeliveryAttempt;

use App\Domain\Shared\UuidV4;

final readonly class DeliveryAttemptId
{
    private function __construct(private UuidV4 $value) {}

    public static function generate(): self
    {
        return new self(UuidV4::generate());
    }

    public static function fromString(string $value): self
    {
        return new self(UuidV4::fromString($value));
    }

    public function toString(): string
    {
        return $this->value->toString();
    }
}

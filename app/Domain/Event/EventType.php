<?php

declare(strict_types=1);

namespace App\Domain\Event;

use InvalidArgumentException;

final readonly class EventType
{
    private function __construct(
        private string $value,
    ) {}

    public static function fromString(string $value): self
    {
        if (
            $value === ''
            || mb_strlen($value) > 120
            || preg_match('/^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$/', $value) !== 1
        ) {
            throw new InvalidArgumentException('Event type has an invalid format.');
        }

        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Event;

final readonly class EventIngressIdempotencyKey
{
    private function __construct(private string $value) {}

    public static function fromOptional(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        if (preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $value) !== 1) {
            throw new InvalidEventIngressIdempotencyKey('Idempotency-Key must be 1..128 characters from [A-Za-z0-9._:-].');
        }

        return new self($value);
    }

    public function digest(): string
    {
        return hash('sha256', "event-ingress\n".$this->value);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Event;

use InvalidArgumentException;
use JsonException;
use stdClass;

final readonly class EventPayload
{
    private function __construct(
        private stdClass $value,
    ) {}

    public static function fromValue(mixed $value): self
    {
        if (! $value instanceof stdClass) {
            throw new InvalidArgumentException('Event payload must be a JSON object.');
        }

        try {
            $json = json_encode($value, JSON_THROW_ON_ERROR);
            $normalised = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Event payload must be JSON serializable.');
        }

        if (! $normalised instanceof stdClass) {
            throw new InvalidArgumentException('Event payload must be a JSON object.');
        }

        return new self($normalised);
    }

    public function toObject(): stdClass
    {
        try {
            $json = json_encode($this->value, JSON_THROW_ON_ERROR);
            $payload = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Event payload must be JSON serializable.');
        }

        if (! $payload instanceof stdClass) {
            throw new InvalidArgumentException('Event payload must be a JSON object.');
        }

        return $payload;
    }
}

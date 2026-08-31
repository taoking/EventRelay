<?php

declare(strict_types=1);

namespace App\Application\Event;

use JsonException;
use LogicException;
use stdClass;

final readonly class EventIngressRequestFingerprint
{
    private function __construct(
        private string $value,
        private string $canonicalPayloadJson,
    ) {}

    public static function from(string $eventType, stdClass $payload): self
    {
        $canonicalPayloadJson = self::canonicalPayloadJson($payload);

        return new self(
            hash('sha256', "v1\n{$eventType}\n{$canonicalPayloadJson}"),
            $canonicalPayloadJson,
        );
    }

    public static function canonicalPayloadJson(stdClass $payload): string
    {
        try {
            return json_encode(
                self::canonicalize($payload),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new LogicException('Validated event payload must be JSON serializable.', 0, $exception);
        }
    }

    public function value(): string
    {
        return $this->value;
    }

    public function canonicalPayload(): string
    {
        return $this->canonicalPayloadJson;
    }

    private static function canonicalize(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);

            $canonical = new stdClass;
            foreach ($properties as $key => $property) {
                $canonical->{$key} = self::canonicalize($property);
            }

            return $canonical;
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::canonicalize($item);
            }
        }

        return $value;
    }
}

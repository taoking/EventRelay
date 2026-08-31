<?php

declare(strict_types=1);

namespace App\Application\DeadLetter;

use App\Domain\DeliveryAttempt\DeliveryFailureType;
use App\Domain\Event\EventType;
use InvalidArgumentException;

final readonly class DeadLetterFilter
{
    public const int DefaultLimit = 50;

    public const int MaximumLimit = 100;

    /**
     * @param  array<string, mixed>  $query
     */
    public static function fromQuery(array $query): self
    {
        $allowed = ['endpoint_id', 'event_type', 'failure_type', 'response_status', 'limit', 'cursor'];
        foreach (array_keys($query) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw new InvalidDeadLetterFilter('Unsupported dead-letter filter.');
            }
        }

        $endpointId = self::optionalString($query, 'endpoint_id');
        if ($endpointId !== null && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $endpointId) !== 1) {
            throw new InvalidDeadLetterFilter('Endpoint id must be a UUID v4.');
        }

        $eventType = self::optionalString($query, 'event_type');
        if ($eventType !== null) {
            try {
                $eventType = EventType::fromString($eventType)->toString();
            } catch (InvalidArgumentException) {
                throw new InvalidDeadLetterFilter('Event type is invalid.');
            }
        }

        $failureType = self::optionalString($query, 'failure_type');
        if ($failureType !== null && DeliveryFailureType::tryFrom($failureType) === null) {
            throw new InvalidDeadLetterFilter('Failure type is invalid.');
        }

        $responseStatus = self::optionalString($query, 'response_status');
        if ($responseStatus !== null && (! ctype_digit($responseStatus) || (int) $responseStatus < 100 || (int) $responseStatus > 599)) {
            throw new InvalidDeadLetterFilter('Response status is invalid.');
        }

        $limit = self::optionalString($query, 'limit');
        if ($limit !== null && (! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > self::MaximumLimit)) {
            throw new InvalidDeadLetterFilter('Limit is invalid.');
        }

        return new self(
            $endpointId === null ? null : strtolower($endpointId),
            $eventType,
            $failureType,
            $responseStatus === null ? null : (int) $responseStatus,
            $limit === null ? self::DefaultLimit : (int) $limit,
        );
    }

    public function __construct(
        public ?string $endpointId,
        public ?string $eventType,
        public ?string $failureType,
        public ?int $responseStatus,
        public int $limit,
    ) {}

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'endpoint_id' => $this->endpointId,
            'event_type' => $this->eventType,
            'failure_type' => $this->failureType,
            'response_status' => $this->responseStatus,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private static function optionalString(array $query, string $key): ?string
    {
        if (! array_key_exists($key, $query)) {
            return null;
        }

        if (! is_string($query[$key]) || $query[$key] === '') {
            throw new InvalidDeadLetterFilter("{$key} is invalid.");
        }

        return $query[$key];
    }
}

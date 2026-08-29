<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

use App\Domain\Endpoint\EndpointId;
use App\Domain\Event\EventType;
use InvalidArgumentException;

final readonly class EndpointSubscriptions
{
    /**
     * @param  list<EventType>  $types
     */
    private function __construct(
        private EndpointId $endpointId,
        private array $types,
    ) {}

    /**
     * @param  array<mixed>  $types
     */
    public static function replace(EndpointId $endpointId, array $types): self
    {
        /** @var array<string, EventType> $uniqueTypes */
        $uniqueTypes = [];

        foreach ($types as $type) {
            if (! is_string($type)) {
                throw new InvalidArgumentException('Subscription event types must be strings.');
            }

            $eventType = EventType::fromString($type);
            $uniqueTypes[$eventType->toString()] = $eventType;
        }

        ksort($uniqueTypes, SORT_STRING);

        return new self($endpointId, array_values($uniqueTypes));
    }

    public function endpointId(): EndpointId
    {
        return $this->endpointId;
    }

    /**
     * @return list<EventType>
     */
    public function types(): array
    {
        return $this->types;
    }
}

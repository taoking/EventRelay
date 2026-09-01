<?php

declare(strict_types=1);

namespace App\Infrastructure\RabbitMq;

use App\Domain\Delivery\DeliveryId;
use InvalidArgumentException;
use JsonException;

final readonly class RabbitMqDeliveryEnvelope
{
    private function __construct(public DeliveryId $deliveryId) {}

    public static function fromBody(string $body): self
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidRabbitMqDeliveryEnvelope('The RabbitMQ delivery envelope is not valid JSON.', 0, $exception);
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidRabbitMqDeliveryEnvelope('The RabbitMQ delivery envelope has an invalid shape.');
        }

        $keys = array_keys($decoded);
        sort($keys);

        if ($keys !== ['delivery_id', 'type', 'v']) {
            throw new InvalidRabbitMqDeliveryEnvelope('The RabbitMQ delivery envelope has an invalid shape.');
        }

        if ($decoded['v'] !== 1 || $decoded['type'] !== 'delivery.process' || ! is_string($decoded['delivery_id'])) {
            throw new InvalidRabbitMqDeliveryEnvelope('The RabbitMQ delivery envelope contains invalid values.');
        }

        try {
            return new self(DeliveryId::fromString($decoded['delivery_id']));
        } catch (InvalidArgumentException $exception) {
            throw new InvalidRabbitMqDeliveryEnvelope('The RabbitMQ delivery envelope contains an invalid delivery ID.', 0, $exception);
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMq;

use App\Application\Delivery\DeliveryTransportUnavailable;
use App\Domain\Delivery\DeliveryId;
use App\Infrastructure\RabbitMq\InvalidRabbitMqDeliveryEnvelope;
use App\Infrastructure\RabbitMq\RabbitMqConfiguration;
use App\Infrastructure\RabbitMq\RabbitMqDeliveryEnvelope;
use App\Infrastructure\RabbitMq\RabbitMqDeliveryPublisher;
use App\Infrastructure\RabbitMq\RabbitMqDeliveryTransport;
use App\Infrastructure\RabbitMq\RabbitMqPublicationUnavailable;
use Illuminate\Support\Facades\Log;
use LogicException;
use Tests\TestCase;

final class RabbitMqDeliveryTransportContractTest extends TestCase
{
    public function test_known_broker_confirm_or_routing_failure_is_translated_without_logging_the_message_body(): void
    {
        $deliveryId = DeliveryId::fromString('9db4d301-f44a-4dab-a545-6f9046cbeb6f');
        Log::spy();
        $transport = new RabbitMqDeliveryTransport(
            new class implements RabbitMqDeliveryPublisher
            {
                public function publish(DeliveryId $deliveryId): void
                {
                    throw new RabbitMqPublicationUnavailable('Confirm NACK or mandatory return.');
                }
            },
            $this->configuration(),
        );

        try {
            $transport->publish($deliveryId);
            self::fail('Known broker confirmation failures must be translated.');
        } catch (DeliveryTransportUnavailable $exception) {
            self::assertSame('rabbitmq', $exception->transport);
            self::assertSame($deliveryId->toString(), $exception->deliveryId->toString());
            self::assertInstanceOf(RabbitMqPublicationUnavailable::class, $exception->getPrevious());
        }

        Log::shouldHaveReceived('warning')->once()->with(
            'Delivery transport publication failed.',
            \Mockery::on(static fn (array $context): bool => $context['delivery_id'] === $deliveryId->toString()
                && $context['transport'] === 'rabbitmq'
                && $context['exchange'] === 'eventrelay.delivery'
                && $context['queue'] === 'eventrelay.deliveries'
                && ! array_key_exists('body', $context)
                && ! array_key_exists('password', $context)),
        );
    }

    public function test_unknown_publisher_programming_error_propagates_without_becoming_transport_unavailable(): void
    {
        $transport = new RabbitMqDeliveryTransport(
            new class implements RabbitMqDeliveryPublisher
            {
                public function publish(DeliveryId $deliveryId): void
                {
                    throw new LogicException('Unexpected publisher programming error.');
                }
            },
            $this->configuration(),
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Unexpected publisher programming error.');
        $transport->publish(DeliveryId::fromString('9db4d301-f44a-4dab-a545-6f9046cbeb6f'));
    }

    public function test_envelope_validation_is_strict_and_returns_only_a_uuid_delivery_identity(): void
    {
        $deliveryId = '9db4d301-f44a-4dab-a545-6f9046cbeb6f';
        self::assertSame(
            $deliveryId,
            RabbitMqDeliveryEnvelope::fromBody('{"v":1,"type":"delivery.process","delivery_id":"'.$deliveryId.'"}')->deliveryId->toString(),
        );

        $this->expectException(InvalidRabbitMqDeliveryEnvelope::class);
        RabbitMqDeliveryEnvelope::fromBody('{"v":1,"type":"delivery.process","delivery_id":"'.$deliveryId.'","payload":{}}');
    }

    private function configuration(): RabbitMqConfiguration
    {
        return new RabbitMqConfiguration(
            'rabbitmq',
            5672,
            'eventrelay',
            'not-logged',
            '/',
            'eventrelay.delivery',
            'eventrelay.deliveries',
            'delivery.process',
            10,
            5.0,
        );
    }
}

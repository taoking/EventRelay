<?php

declare(strict_types=1);

namespace Runtime;

final readonly class RabbitConsumerScenario implements RuntimeScenario
{
    public function name(): string
    {
        return 'rabbit-consumer';
    }

    public function transport(): string
    {
        return 'rabbitmq';
    }

    public function run(RuntimeContext $context): void
    {
        $deliveryId = $context->createPendingDelivery('rabbit-consumer');
        $consumer = $context->docker->startArtisan(['deliveries:consume-rabbitmq'], 'continuous RabbitMQ Delivery consumer');
        $context->eventually()->until(
            fn (): bool => $consumer->isRunning() && $context->rabbitConsumerCount() === 1,
            Deadline::afterSeconds(30),
            'RabbitMQ consumer readiness',
            $context->cancellation,
        );
        $context->eventually()->during(
            fn (): bool => $consumer->isRunning() && $context->rabbitConsumerCount() === 1,
            Deadline::afterSeconds(1.2),
            'one bounded RabbitMQ idle wait window',
            $context->cancellation,
        );
        $context->docker->artisan(['outbox:publish', '--limit=1'], 'publish valid RabbitMQ delivery envelope');
        $context->eventually()->until(
            fn (): bool => $context->deliveryStatus($deliveryId) === 'failed' && $context->rabbitQueueEmpty(),
            Deadline::afterSeconds(45),
            'valid RabbitMQ envelope processed and acknowledged',
            $context->cancellation,
        );
        $exitCode = $context->docker->terminateArtisan($consumer, $context->eventually());
        if ($exitCode !== 0 || $context->processes->runningCount() !== 0) {
            throw new RuntimeException('Continuous RabbitMQ consumer did not stop cleanly after SIGTERM.');
        }
        $context->observe('delivery_id', $deliveryId);
        $context->observe('consumer_exit', 0);
        $context->observe('valid_envelope_acknowledged', true);
    }
}

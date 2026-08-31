<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Queue;

use App\Infrastructure\Queue\ProcessDeliveryJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Tests\TestCase;

final class ProcessDeliveryJobTest extends TestCase
{
    public function test_it_uses_the_dedicated_redis_deliveries_queue_without_queue_level_uniqueness(): void
    {
        $deliveryId = 'd2b4d301-f44a-4dab-a545-6f9046cbeb6f';
        $job = new ProcessDeliveryJob($deliveryId);

        self::assertInstanceOf(ShouldQueue::class, $job);
        self::assertSame('redis', $job->connection);
        self::assertSame('deliveries', $job->queue);
        self::assertSame(1, $job->tries);
        self::assertSame(10, $job->timeout);
    }

    public function test_its_serialized_payload_contains_only_the_delivery_uuid_and_queue_metadata(): void
    {
        $deliveryId = 'd2b4d301-f44a-4dab-a545-6f9046cbeb6f';
        $serialized = serialize(new ProcessDeliveryJob($deliveryId));

        self::assertStringContainsString($deliveryId, $serialized);
        self::assertStringNotContainsString('order_1001', $serialized);
        self::assertStringNotContainsString('https://example.test/webhooks', $serialized);
        self::assertStringNotContainsString('DeliveryRecord', $serialized);
        self::assertStringNotContainsString('EndpointRecord', $serialized);
    }
}

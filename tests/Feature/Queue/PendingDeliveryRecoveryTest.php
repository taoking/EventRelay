<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Application\Delivery\CreateDelivery;
use App\Application\Delivery\DeliveryQueue;
use App\Application\Delivery\EnqueuePendingDeliveries;
use App\Domain\Delivery\DeliveryId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PendingDeliveryRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_recovery_reads_pending_deliveries_in_stable_order_and_honors_the_limit(): void
    {
        $first = $this->createPendingDelivery('First recovery endpoint');
        $second = $this->createPendingDelivery('Second recovery endpoint');
        $this->createPendingDelivery('Third recovery endpoint');
        $queued = [];

        $this->app->instance(
            DeliveryQueue::class,
            new class($queued) implements DeliveryQueue
            {
                /**
                 * @param  list<string>  $queued
                 */
                public function __construct(array &$queued)
                {
                    $this->queued = &$queued;
                }

                /**
                 * @var list<string>
                 */
                private array $queued;

                public function enqueue(DeliveryId $deliveryId): void
                {
                    $this->queued[] = $deliveryId->toString();
                }

                public function schedule(DeliveryId $deliveryId, \DateTimeImmutable $availableAt): void {}
            },
        );

        $result = app(EnqueuePendingDeliveries::class)->handle(2);

        self::assertSame(2, $result->enqueued);
        self::assertSame(0, $result->failed);
        self::assertSame([$first, $second], $queued);
    }

    private function createPendingDelivery(string $endpointName): string
    {
        $eventId = (string) $this->postJson('/api/events', [
            'type' => 'order.paid',
            'payload' => (object) ['source' => 'recovery-test'],
        ])->assertCreated()->json('data.id');
        $endpointId = (string) $this->postJson('/api/endpoints', [
            'name' => $endpointName,
            'url' => 'https://example.test/webhooks/recovery',
        ])->assertCreated()->json('data.id');

        return app(CreateDelivery::class)->handle($eventId, $endpointId)->id;
    }
}

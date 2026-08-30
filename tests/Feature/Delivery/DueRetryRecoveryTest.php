<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Application\Clock\Clock;
use App\Application\Delivery\CreateDelivery;
use App\Application\Delivery\DeliveryQueue;
use App\Application\Delivery\EnqueueDueRetries;
use App\Domain\Delivery\DeliveryId;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FrozenClock;
use Tests\TestCase;

final class DueRetryRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_due_retry_recovery_uses_stable_order_honours_limit_and_does_not_enqueue_future_retries(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-08-31T12:00:00+00:00'));
        $queued = [];
        $this->app->instance(Clock::class, $clock);
        $this->app->instance(DeliveryQueue::class, new class($queued) implements DeliveryQueue
        {
            /** @param list<string> $queued */
            public function __construct(array &$queued)
            {
                $this->queued = &$queued;
            }

            /** @var list<string> */
            private array $queued;

            public function enqueue(DeliveryId $deliveryId): void
            {
                $this->queued[] = $deliveryId->toString();
            }

            public function schedule(DeliveryId $deliveryId, DateTimeImmutable $availableAt): void {}
        });
        $first = $this->createScheduledDelivery('First due retry', '2026-08-31 11:59:58');
        $second = $this->createScheduledDelivery('Second due retry', '2026-08-31 11:59:59');
        $this->createScheduledDelivery('Future retry', '2026-08-31 12:00:01');

        $result = app(EnqueueDueRetries::class)->handle(2);

        self::assertSame(2, $result->enqueued);
        self::assertSame(0, $result->failed);
        self::assertSame([$first, $second], $queued);
    }

    private function createScheduledDelivery(string $endpointName, string $nextAttemptAt): string
    {
        $endpointId = (string) $this->postJson('/api/endpoints', [
            'name' => $endpointName,
            'url' => 'https://receiver.example/due-recovery',
        ])->assertCreated()->json('data.id');
        $eventId = (string) $this->postJson('/api/events', [
            'type' => 'order.paid',
            'payload' => (object) [],
        ])->assertCreated()->json('data.id');
        $deliveryId = app(CreateDelivery::class)->handle($eventId, $endpointId)->id;
        DB::table('deliveries')->where('public_id', $deliveryId)->update([
            'status' => 'retry_scheduled',
            'next_attempt_at' => $nextAttemptAt,
        ]);

        return $deliveryId;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Application\Clock\Clock;
use App\Application\Delivery\CreateDelivery;
use App\Application\Delivery\EnqueueDueRetries;
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
        $this->app->instance(Clock::class, $clock);
        $first = $this->createScheduledDelivery('First due retry', '2026-08-31 11:59:58');
        $second = $this->createScheduledDelivery('Second due retry', '2026-08-31 11:59:59');
        $this->createScheduledDelivery('Future retry', '2026-08-31 12:00:01');

        $result = app(EnqueueDueRetries::class)->handle(2);

        self::assertSame(2, $result->ensured);
        self::assertSame([$first, $second], DB::table('delivery_outbox_messages')
            ->join('deliveries', 'delivery_outbox_messages.delivery_id', '=', 'deliveries.id')
            ->orderBy('delivery_outbox_messages.available_at')
            ->orderBy('delivery_outbox_messages.id')
            ->pluck('deliveries.public_id')
            ->all());
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

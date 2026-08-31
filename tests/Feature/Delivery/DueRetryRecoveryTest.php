<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Application\Clock\Clock;
use App\Application\Delivery\CreateDelivery;
use App\Application\Delivery\EnqueueDueRetries;
use App\Application\Delivery\ProcessPendingDelivery;
use App\Application\Delivery\WebhookRequest;
use App\Application\Delivery\WebhookResponse;
use App\Application\Delivery\WebhookTarget;
use App\Application\Delivery\WebhookTargetResolver;
use App\Application\Delivery\WebhookTransport;
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
        $this->app->instance(Clock::class, $clock);
        $this->app->instance(WebhookTargetResolver::class, new class implements WebhookTargetResolver
        {
            public function resolve(string $targetUrl): WebhookTarget
            {
                return new WebhookTarget($targetUrl, 'receiver.example', 443, '1.1.1.1');
            }
        });
        $this->app->instance(WebhookTransport::class, new class implements WebhookTransport
        {
            public function send(WebhookTarget $target, WebhookRequest $request): WebhookResponse
            {
                return new WebhookResponse(500, 1);
            }
        });
        $first = $this->createScheduledDelivery($clock, 'First due retry', '2026-08-31 11:59:58');
        $second = $this->createScheduledDelivery($clock, 'Second due retry', '2026-08-31 11:59:59');
        $this->createScheduledDelivery($clock, 'Future retry', '2026-08-31 12:00:01');
        $clock->set(new DateTimeImmutable('2026-08-31T12:00:00+00:00'));
        DB::table('delivery_outbox_messages')->update(['status' => 'published']);

        $result = app(EnqueueDueRetries::class)->handle(2);

        self::assertSame(2, $result->ensured);
        self::assertSame([$first, $second], DB::table('delivery_outbox_messages')
            ->join('deliveries', 'delivery_outbox_messages.delivery_id', '=', 'deliveries.id')
            ->where('delivery_outbox_messages.status', 'pending')
            ->orderBy('delivery_outbox_messages.available_at')
            ->orderBy('delivery_outbox_messages.id')
            ->pluck('deliveries.public_id')
            ->all());
    }

    private function createScheduledDelivery(FrozenClock $clock, string $endpointName, string $nextAttemptAt): string
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
        $clock->set((new DateTimeImmutable($nextAttemptAt))->modify('-10 seconds'));
        app(ProcessPendingDelivery::class)->handle(DeliveryId::fromString($deliveryId));

        return $deliveryId;
    }
}

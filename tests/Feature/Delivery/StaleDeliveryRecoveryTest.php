<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Application\Clock\Clock;
use App\Application\Delivery\CreateDelivery;
use App\Application\Delivery\DeliveryExecutionConflict;
use App\Application\Delivery\DeliveryExecutionRepository;
use App\Application\Delivery\RecoverStaleDelivery;
use App\Domain\Delivery\DeliveryId;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FrozenClock;
use Tests\TestCase;

final class StaleDeliveryRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_started_attempt_is_abandoned_and_a_late_finalize_cannot_overwrite_recovery(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-08-31T11:59:00+00:00'));
        $this->app->instance(Clock::class, $clock);
        $deliveryId = $this->createDelivery();
        $execution = app(DeliveryExecutionRepository::class);
        $claimed = $execution->claim(DeliveryId::fromString($deliveryId), $clock->now());
        self::assertNotNull($claimed);

        $clock->set(new DateTimeImmutable('2026-08-31T12:00:00+00:00'));
        $recovered = app(RecoverStaleDelivery::class)->handle(DeliveryId::fromString($deliveryId));

        self::assertNotNull($recovered);
        self::assertSame('abandoned', $recovered->attempt->status()->value);
        self::assertSame('stale_processing', $recovered->attempt->failureType()?->value);
        self::assertSame('retry_scheduled', $recovered->delivery->status()->value);
        $this->assertDatabaseHas('deliveries', ['public_id' => $deliveryId, 'status' => 'retry_scheduled']);
        $this->assertDatabaseHas('delivery_attempts', ['attempt_number' => 1, 'status' => 'abandoned', 'failure_type' => 'stale_processing']);
        $this->assertDatabaseHas('delivery_outbox_messages', [
            'dedupe_key' => "delivery:{$deliveryId}:attempt:2",
            'attempt_number' => 2,
            'available_at' => '2026-08-31 12:00:10',
            'status' => 'pending',
        ]);

        $this->expectException(DeliveryExecutionConflict::class);
        $execution->finalize(
            $claimed->delivery->succeed($clock->now()),
            $claimed->attempt->succeed(200, 1, $clock->now()),
        );
    }

    public function test_stale_recovery_is_a_no_op_at_fifty_nine_seconds_and_after_a_terminal_finalize(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-08-31T12:00:00+00:00'));
        $this->app->instance(Clock::class, $clock);
        $deliveryId = $this->createDelivery();
        $execution = app(DeliveryExecutionRepository::class);
        $claimed = $execution->claim(DeliveryId::fromString($deliveryId), $clock->now());
        self::assertNotNull($claimed);

        $clock->set(new DateTimeImmutable('2026-08-31T12:00:59+00:00'));
        self::assertNull(app(RecoverStaleDelivery::class)->handle(DeliveryId::fromString($deliveryId)));

        $execution->finalize(
            $claimed->delivery->succeed($clock->now()),
            $claimed->attempt->succeed(204, 2, $clock->now()),
        );
        $clock->set(new DateTimeImmutable('2026-08-31T12:02:00+00:00'));

        self::assertNull(app(RecoverStaleDelivery::class)->handle(DeliveryId::fromString($deliveryId)));
        self::assertSame('succeeded', DB::table('deliveries')->where('public_id', $deliveryId)->value('status'));
    }

    private function createDelivery(): string
    {
        $endpointId = (string) $this->postJson('/api/endpoints', [
            'name' => 'Stale recovery receiver',
            'url' => 'https://receiver.example/stale',
        ])->assertCreated()->json('data.id');
        $eventId = (string) $this->postJson('/api/events', [
            'type' => 'order.paid',
            'payload' => (object) ['order_id' => 'stale-1001'],
        ])->assertCreated()->json('data.id');

        return app(CreateDelivery::class)->handle($eventId, $endpointId)->id;
    }
}

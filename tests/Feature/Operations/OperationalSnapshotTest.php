<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Application\Clock\Clock;
use App\Application\Delivery\CreateDelivery;
use App\Application\Delivery\DeliveryExecutionIntent;
use App\Application\Delivery\DeliveryOutboxPublisherRepository;
use App\Application\Delivery\DeliveryOutboxWriter;
use App\Application\Delivery\DueRetryFinder;
use App\Application\Delivery\StaleDeliveryFinder;
use App\Application\Operations\CollectOperationalSnapshot;
use App\Application\Operations\OperationalSnapshotConsistencyViolation;
use App\Domain\Delivery\DeliveryId;
use App\Domain\Delivery\DeliveryStatus;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FrozenClock;
use Tests\TestCase;

final class OperationalSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_outbox_due_metric_is_equivalent_to_the_production_claim_predicate_at_one_clock_instant(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-09-15T12:00:00+00:00'));
        $this->app->instance(Clock::class, $clock);
        $rows = [
            $this->createDeliveryWithOutbox($clock->now()->sub(new DateInterval('PT20S'))),
            $this->createDeliveryWithOutbox($clock->now()),
            $this->createDeliveryWithOutbox($clock->now()),
            $this->createDeliveryWithOutbox($clock->now()),
            $this->createDeliveryWithOutbox($clock->now()),
            $this->createDeliveryWithOutbox($clock->now()),
        ];

        $this->setOutbox($rows[1], 'pending', $clock->now(), null);
        $this->setOutbox($rows[2], 'pending', $clock->now()->add(new DateInterval('PT1M')), null);
        $this->setOutbox($rows[3], 'publishing', $clock->now(), $clock->now()->sub(new DateInterval('PT1S')));
        $this->setOutbox($rows[4], 'publishing', null, $clock->now()->add(new DateInterval('PT1M')));
        $this->setOutbox($rows[5], 'publishing', $clock->now()->add(new DateInterval('PT1M')), $clock->now()->sub(new DateInterval('PT1S')));

        $snapshot = app(CollectOperationalSnapshot::class)->handle();
        self::assertSame(3, $snapshot->outboxDuePending);
        self::assertSame(20, $snapshot->outboxOldestDueAgeSeconds);

        $claimed = app(DeliveryOutboxPublisherRepository::class)->claim(
            10,
            $clock->now(),
            $clock->now()->add(new DateInterval('PT1M')),
        );

        self::assertCount($snapshot->outboxDuePending, $claimed);
        self::assertSame([$rows[0], $rows[1], $rows[3]], array_map(
            static fn ($message): string => $message->intent->deliveryId->toString(),
            $claimed,
        ));
    }

    public function test_retry_and_stale_metrics_share_production_finder_thresholds(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-09-15T12:00:00+00:00'));
        $this->app->instance(Clock::class, $clock);
        $retryDue = $this->createDeliveryWithOutbox($clock->now());
        $retryFuture = $this->createDeliveryWithOutbox($clock->now());
        DB::table('deliveries')->where('public_id', $retryDue)->update([
            'status' => DeliveryStatus::RetryScheduled->value,
            'next_attempt_at' => $clock->now(),
        ]);
        DB::table('deliveries')->where('public_id', $retryFuture)->update([
            'status' => DeliveryStatus::RetryScheduled->value,
            'next_attempt_at' => $clock->now()->add(new DateInterval('PT1S')),
        ]);

        $before = $this->createStaleCandidate($clock->now()->sub(new DateInterval('PT59S')));
        $at = $this->createStaleCandidate($clock->now()->sub(new DateInterval('PT60S')));
        $after = $this->createStaleCandidate($clock->now()->sub(new DateInterval('PT61S')));
        self::assertNotSame($before, $at);
        self::assertNotSame($at, $after);

        $snapshot = app(CollectOperationalSnapshot::class)->handle();
        self::assertSame(1, $snapshot->deliveryRetriesDue);
        self::assertSame(2, $snapshot->deliveryStaleProcessingCandidates);
        self::assertCount($snapshot->deliveryRetriesDue, app(DueRetryFinder::class)->findDue($clock->now(), 100));
        self::assertCount(
            $snapshot->deliveryStaleProcessingCandidates,
            app(StaleDeliveryFinder::class)->findStale($clock->now()->sub(new DateInterval('PT60S')), 100),
        );
    }

    public function test_snapshot_uses_five_constant_aggregate_queries_and_does_not_mutate_business_state(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-09-15T12:00:00+00:00'));
        $this->app->instance(Clock::class, $clock);
        $deliveryId = $this->createDeliveryWithOutbox($clock->now());
        $before = [
            'delivery' => (array) DB::table('deliveries')->where('public_id', $deliveryId)->first(),
            'outbox' => (array) DB::table('delivery_outbox_messages')->where('delivery_id', DB::table('deliveries')->where('public_id', $deliveryId)->value('id'))->first(),
            'attempts' => DB::table('delivery_attempts')->count(),
        ];
        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            app(CollectOperationalSnapshot::class)->handle();
            $queries = $connection->getQueryLog();
        } finally {
            $connection->disableQueryLog();
        }

        self::assertCount(5, $queries);
        self::assertSame($before['delivery'], (array) DB::table('deliveries')->where('public_id', $deliveryId)->first());
        self::assertSame($before['outbox'], (array) DB::table('delivery_outbox_messages')->where('delivery_id', DB::table('deliveries')->where('public_id', $deliveryId)->value('id'))->first());
        self::assertSame($before['attempts'], DB::table('delivery_attempts')->count());
    }

    public function test_unknown_persisted_status_fails_closed_without_becoming_a_metric_label(): void
    {
        $deliveryId = $this->createDeliveryWithOutbox(new DateTimeImmutable('2026-09-15T12:00:00+00:00'));
        DB::table('deliveries')->where('public_id', $deliveryId)->update(['status' => 'unknown_status']);

        $this->expectException(OperationalSnapshotConsistencyViolation::class);
        app(CollectOperationalSnapshot::class)->handle();
    }

    private function createDeliveryWithOutbox(DateTimeImmutable $createdAt): string
    {
        $suffix = substr(str_replace('-', '', (string) Str::uuid()), 0, 12);
        $endpointId = (string) $this->postJson('/api/endpoints', [
            'name' => "Operations endpoint {$suffix}",
            'url' => 'https://receiver.example/operations',
        ])->assertCreated()->json('data.id');
        $eventId = (string) $this->postJson('/api/events', [
            'type' => 'order.paid',
            'payload' => (object) ['operation' => $suffix],
        ])->assertCreated()->json('data.id');
        $delivery = app(CreateDelivery::class)->handle($eventId, $endpointId);
        app(DeliveryOutboxWriter::class)->schedule(
            new DeliveryExecutionIntent(DeliveryId::fromString($delivery->id), 1, null),
            $createdAt,
        );
        DB::table('deliveries')->where('public_id', $delivery->id)->update([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        DB::table('delivery_outbox_messages')->where('dedupe_key', "delivery:{$delivery->id}:attempt:1")->update([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return $delivery->id;
    }

    private function setOutbox(string $deliveryId, string $status, ?DateTimeImmutable $availableAt, ?DateTimeImmutable $claimedUntil): void
    {
        $internalId = DB::table('deliveries')->where('public_id', $deliveryId)->value('id');
        DB::table('delivery_outbox_messages')->where('delivery_id', $internalId)->update([
            'status' => $status,
            'available_at' => $availableAt,
            'claimed_until' => $claimedUntil,
            'claim_token' => $claimedUntil === null ? null : 'f7bfba9a-51c9-4eb5-a026-e0414549c6ca',
        ]);
    }

    private function createStaleCandidate(DateTimeImmutable $startedAt): string
    {
        $deliveryId = $this->createDeliveryWithOutbox($startedAt);
        $deliveryInternalId = DB::table('deliveries')->where('public_id', $deliveryId)->value('id');
        DB::table('deliveries')->where('id', $deliveryInternalId)->update([
            'status' => DeliveryStatus::Processing->value,
            'next_attempt_at' => null,
        ]);
        DB::table('delivery_attempts')->insert([
            'public_id' => (string) Str::uuid(),
            'delivery_id' => $deliveryInternalId,
            'attempt_number' => 1,
            'status' => 'started',
            'started_at' => $startedAt,
            'created_at' => $startedAt,
            'updated_at' => $startedAt,
        ]);

        return $deliveryId;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Application\Clock\Clock;
use App\Application\Delivery\CreateDelivery;
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

final class DeliveryRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_retryable_failure_creates_a_durable_outbox_intent_in_the_finalize_transaction_and_the_due_second_attempt_succeeds(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-08-31T12:00:00+00:00'));
        $transport = new class implements WebhookTransport
        {
            /** @var list<WebhookResponse> */
            public array $responses;

            /** @var list<string> */
            public array $attemptHeaders = [];

            public function __construct()
            {
                $this->responses = [new WebhookResponse(500, 8), new WebhookResponse(200, 9)];
            }

            public function send(WebhookTarget $target, WebhookRequest $request): WebhookResponse
            {
                $this->attemptHeaders[] = $request->headers['X-EventRelay-Attempt'];

                return array_shift($this->responses) ?? throw new \LogicException('Unexpected fourth HTTP send.');
            }
        };
        $this->bindExecutionDependencies($clock, $transport);
        $deliveryId = $this->createDelivery();

        app(ProcessPendingDelivery::class)->handle(DeliveryId::fromString($deliveryId));

        $this->assertDatabaseHas('deliveries', [
            'public_id' => $deliveryId,
            'status' => 'retry_scheduled',
            'next_attempt_at' => '2026-08-31 12:00:10',
        ]);
        $this->getJson("/api/deliveries/{$deliveryId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'retry_scheduled');
        $this->assertDatabaseHas('delivery_outbox_messages', [
            'delivery_id' => DB::table('deliveries')->where('public_id', $deliveryId)->value('id'),
            'message_type' => 'delivery.process',
            'dedupe_key' => "delivery:{$deliveryId}:attempt:2",
            'attempt_number' => 2,
            'available_at' => '2026-08-31 12:00:10',
            'status' => 'pending',
        ]);

        $clock->set(new DateTimeImmutable('2026-08-31T12:00:09+00:00'));
        app(ProcessPendingDelivery::class)->handle(DeliveryId::fromString($deliveryId));
        self::assertSame(['1'], $transport->attemptHeaders);

        $clock->set(new DateTimeImmutable('2026-08-31T12:00:10+00:00'));
        app(ProcessPendingDelivery::class)->handle(DeliveryId::fromString($deliveryId));

        $this->assertDatabaseHas('deliveries', ['public_id' => $deliveryId, 'status' => 'succeeded', 'next_attempt_at' => null]);
        $this->assertDatabaseHas('delivery_attempts', ['attempt_number' => 1, 'status' => 'failed', 'response_status' => 500]);
        $this->assertDatabaseHas('delivery_attempts', ['attempt_number' => 2, 'status' => 'succeeded', 'response_status' => 200]);
        self::assertSame(['1', '2'], $transport->attemptHeaders);
        self::assertSame(2, DB::table('delivery_attempts')->count());
    }

    public function test_a_non_retryable_http_status_is_terminal_without_a_delayed_job(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-08-31T12:00:00+00:00'));
        $this->bindExecutionDependencies($clock, new class implements WebhookTransport
        {
            public function send(WebhookTarget $target, WebhookRequest $request): WebhookResponse
            {
                return new WebhookResponse(400, 4);
            }
        });
        $deliveryId = $this->createDelivery();

        app(ProcessPendingDelivery::class)->handle(DeliveryId::fromString($deliveryId));

        $this->assertDatabaseHas('deliveries', ['public_id' => $deliveryId, 'status' => 'failed', 'next_attempt_at' => null]);
        $this->assertDatabaseCount('delivery_outbox_messages', 0);
    }

    public function test_retryable_failure_keeps_committed_retry_state_and_outbox_intent_without_contacting_redis(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-08-31T12:00:00+00:00'));
        $this->app->instance(Clock::class, $clock);
        $this->app->instance(WebhookTargetResolver::class, new class implements WebhookTargetResolver
        {
            public function resolve(string $url): WebhookTarget
            {
                return new WebhookTarget($url, 'receiver.example', 443, '1.1.1.1');
            }
        });
        $this->app->instance(WebhookTransport::class, new class implements WebhookTransport
        {
            public function send(WebhookTarget $target, WebhookRequest $request): WebhookResponse
            {
                return new WebhookResponse(500, 1);
            }
        });
        $deliveryId = $this->createDelivery();

        app(ProcessPendingDelivery::class)->handle(DeliveryId::fromString($deliveryId));

        $this->assertDatabaseHas('deliveries', ['public_id' => $deliveryId, 'status' => 'retry_scheduled']);
        $this->assertDatabaseHas('delivery_attempts', ['attempt_number' => 1, 'status' => 'failed', 'response_status' => 500]);
        $this->assertDatabaseHas('delivery_outbox_messages', [
            'dedupe_key' => "delivery:{$deliveryId}:attempt:2",
            'status' => 'pending',
        ]);
    }

    private function bindExecutionDependencies(FrozenClock $clock, WebhookTransport $transport): void
    {
        $this->app->instance(Clock::class, $clock);
        $this->app->instance(WebhookTransport::class, $transport);
        $this->app->instance(WebhookTargetResolver::class, new class implements WebhookTargetResolver
        {
            public function resolve(string $url): WebhookTarget
            {
                return new WebhookTarget($url, 'receiver.example', 443, '1.1.1.1');
            }
        });
    }

    private function createDelivery(): string
    {
        $endpointId = (string) $this->postJson('/api/endpoints', [
            'name' => 'Retry receiver',
            'url' => 'https://receiver.example/retry',
        ])->assertCreated()->json('data.id');
        $eventId = (string) $this->postJson('/api/events', [
            'type' => 'order.paid',
            'payload' => (object) ['order_id' => 'retry-1001'],
        ])->assertCreated()->json('data.id');

        return app(CreateDelivery::class)->handle($eventId, $endpointId)->id;
    }
}

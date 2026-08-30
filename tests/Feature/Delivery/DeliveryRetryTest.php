<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Application\Clock\Clock;
use App\Application\Delivery\CreateDelivery;
use App\Application\Delivery\DeliveryQueue;
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

    public function test_a_retryable_failure_is_scheduled_after_commit_and_the_due_second_attempt_succeeds(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-08-31T12:00:00+00:00'));
        $queue = new class implements DeliveryQueue
        {
            /** @var list<array{id: string, at: DateTimeImmutable}> */
            public array $scheduled = [];

            public function enqueue(DeliveryId $deliveryId): void {}

            public function schedule(DeliveryId $deliveryId, DateTimeImmutable $availableAt): void
            {
                $this->scheduled[] = ['id' => $deliveryId->toString(), 'at' => $availableAt];
            }
        };
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
        $this->bindExecutionDependencies($clock, $queue, $transport);
        $deliveryId = $this->createDelivery();

        app(ProcessPendingDelivery::class)->handle(DeliveryId::fromString($deliveryId));

        $this->assertDatabaseHas('deliveries', [
            'public_id' => $deliveryId,
            'status' => 'retry_scheduled',
            'next_attempt_at' => '2026-08-31 12:00:10',
        ]);
        self::assertSame($deliveryId, $queue->scheduled[0]['id']);
        self::assertEquals(new DateTimeImmutable('2026-08-31T12:00:10+00:00'), $queue->scheduled[0]['at']);

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
        $queue = new class implements DeliveryQueue
        {
            public int $scheduled = 0;

            public function enqueue(DeliveryId $deliveryId): void {}

            public function schedule(DeliveryId $deliveryId, DateTimeImmutable $availableAt): void
            {
                $this->scheduled++;
            }
        };
        $this->bindExecutionDependencies($clock, $queue, new class implements WebhookTransport
        {
            public function send(WebhookTarget $target, WebhookRequest $request): WebhookResponse
            {
                return new WebhookResponse(400, 4);
            }
        });
        $deliveryId = $this->createDelivery();

        app(ProcessPendingDelivery::class)->handle(DeliveryId::fromString($deliveryId));

        $this->assertDatabaseHas('deliveries', ['public_id' => $deliveryId, 'status' => 'failed', 'next_attempt_at' => null]);
        self::assertSame(0, $queue->scheduled);
    }

    private function bindExecutionDependencies(FrozenClock $clock, DeliveryQueue $queue, WebhookTransport $transport): void
    {
        $this->app->instance(Clock::class, $clock);
        $this->app->instance(DeliveryQueue::class, $queue);
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

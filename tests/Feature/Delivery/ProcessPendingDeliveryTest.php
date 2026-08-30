<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Application\Delivery\CreateDelivery;
use App\Application\Delivery\ProcessPendingDelivery;
use App\Application\Delivery\UnsafeWebhookTarget;
use App\Application\Delivery\WebhookRequest;
use App\Application\Delivery\WebhookResponse;
use App\Application\Delivery\WebhookTarget;
use App\Application\Delivery\WebhookTargetResolver;
use App\Application\Delivery\WebhookTransport;
use App\Application\Delivery\WebhookTransportFailure;
use App\Domain\Delivery\DeliveryId;
use App\Domain\DeliveryAttempt\DeliveryFailureType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ProcessPendingDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_posts_the_frozen_event_payload_and_atomically_marks_the_delivery_and_attempt_succeeded(): void
    {
        $deliveryId = $this->createDelivery('https://receiver.example/first');
        $transport = new class implements WebhookTransport
        {
            public ?WebhookTarget $target = null;

            public ?WebhookRequest $request = null;

            public function send(WebhookTarget $target, WebhookRequest $request): WebhookResponse
            {
                $this->target = $target;
                $this->request = $request;

                return new WebhookResponse(204, 17);
            }
        };
        $this->app->instance(WebhookTargetResolver::class, new class implements WebhookTargetResolver
        {
            public function resolve(string $url): WebhookTarget
            {
                return new WebhookTarget($url, 'receiver.example', 443, '1.1.1.1');
            }
        });
        $this->app->instance(WebhookTransport::class, $transport);

        app(ProcessPendingDelivery::class)->handle(DeliveryId::fromString($deliveryId));

        $this->assertDatabaseHas('deliveries', ['public_id' => $deliveryId, 'status' => 'succeeded']);
        $this->assertDatabaseHas('delivery_attempts', [
            'attempt_number' => 1,
            'status' => 'succeeded',
            'response_status' => 204,
            'duration_ms' => 17,
        ]);
        self::assertNotNull($transport->request);
        self::assertSame('https://receiver.example/first', $transport->target?->url);
        self::assertSame($deliveryId, $transport->request->headers['X-EventRelay-Delivery-Id']);
        self::assertSame('1', $transport->request->headers['X-EventRelay-Attempt']);
        self::assertSame('EventRelay/0.1', $transport->request->headers['User-Agent']);
        self::assertSame('application/json', $transport->request->headers['Content-Type']);
        self::assertSame([], json_decode($transport->request->body, true, flags: JSON_THROW_ON_ERROR)['payload']);
        self::assertStringContainsString('"payload":{}', $transport->request->body);

        $this->getJson("/api/deliveries/{$deliveryId}/attempts")
            ->assertOk()
            ->assertJsonPath('data.0.attempt_number', 1)
            ->assertJsonPath('data.0.status', 'succeeded')
            ->assertJsonPath('data.0.response_status', 204);

        app(ProcessPendingDelivery::class)->handle(DeliveryId::fromString($deliveryId));
        self::assertSame(1, DB::table('delivery_attempts')->count());
    }

    public function test_non_2xx_and_known_transport_failures_are_terminal_business_failures_without_retry(): void
    {
        $httpDelivery = $this->createDelivery('https://receiver.example/http-status');
        $networkDelivery = $this->createDelivery('https://receiver.example/network-error');
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
                if (str_contains($target->url, 'network-error')) {
                    throw new WebhookTransportFailure(DeliveryFailureType::NetworkError, 'Connection refused.', 9);
                }

                return new WebhookResponse(500, 11);
            }
        });

        app(ProcessPendingDelivery::class)->handle(DeliveryId::fromString($httpDelivery));
        app(ProcessPendingDelivery::class)->handle(DeliveryId::fromString($networkDelivery));

        $this->assertDatabaseHas('deliveries', ['public_id' => $httpDelivery, 'status' => 'failed']);
        $this->assertDatabaseHas('deliveries', ['public_id' => $networkDelivery, 'status' => 'failed']);
        $this->assertDatabaseHas('delivery_attempts', [
            'failure_type' => 'http_status',
            'response_status' => 500,
            'duration_ms' => 11,
        ]);
        $this->assertDatabaseHas('delivery_attempts', [
            'failure_type' => 'network_error',
            'failure_message' => 'Connection refused.',
            'duration_ms' => 9,
        ]);
    }

    public function test_an_unsafe_target_creates_one_failed_attempt_without_calling_transport(): void
    {
        $deliveryId = $this->createDelivery('http://127.0.0.1/blocked');
        $transport = new class implements WebhookTransport
        {
            public int $calls = 0;

            public function send(WebhookTarget $target, WebhookRequest $request): WebhookResponse
            {
                $this->calls++;

                return new WebhookResponse(200, 1);
            }
        };
        $this->app->instance(WebhookTargetResolver::class, new class implements WebhookTargetResolver
        {
            public function resolve(string $url): WebhookTarget
            {
                throw new UnsafeWebhookTarget('Target resolved to an unsafe address.');
            }
        });
        $this->app->instance(WebhookTransport::class, $transport);

        app(ProcessPendingDelivery::class)->handle(DeliveryId::fromString($deliveryId));

        self::assertSame(0, $transport->calls);
        $this->assertDatabaseHas('deliveries', ['public_id' => $deliveryId, 'status' => 'failed']);
        $this->assertDatabaseHas('delivery_attempts', [
            'attempt_number' => 1,
            'status' => 'failed',
            'failure_type' => 'unsafe_target',
        ]);
    }

    public function test_endpoint_updates_do_not_change_an_existing_delivery_target_snapshot(): void
    {
        $deliveryId = $this->createDelivery('https://receiver.example/original');
        $endpointId = DB::table('deliveries')
            ->join('endpoints', 'deliveries.endpoint_id', '=', 'endpoints.id')
            ->where('deliveries.public_id', $deliveryId)
            ->value('endpoints.public_id');

        $this->patchJson("/api/endpoints/{$endpointId}", [
            'url' => 'https://receiver.example/changed',
        ])->assertOk();
        $transport = new class implements WebhookTransport
        {
            public ?WebhookTarget $target = null;

            public function send(WebhookTarget $target, WebhookRequest $request): WebhookResponse
            {
                $this->target = $target;

                return new WebhookResponse(200, 1);
            }
        };
        $this->app->instance(WebhookTargetResolver::class, new class implements WebhookTargetResolver
        {
            public function resolve(string $url): WebhookTarget
            {
                return new WebhookTarget($url, 'receiver.example', 443, '1.1.1.1');
            }
        });
        $this->app->instance(WebhookTransport::class, $transport);

        app(ProcessPendingDelivery::class)->handle(DeliveryId::fromString($deliveryId));

        self::assertSame('https://receiver.example/original', $transport->target?->url);
    }

    public function test_an_unknown_transport_bug_propagates_and_leaves_the_claimed_attempt_visible_for_future_recovery(): void
    {
        $deliveryId = $this->createDelivery('https://receiver.example/first');
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
                throw new \LogicException('test programming defect');
            }
        });

        $this->expectException(\LogicException::class);

        try {
            app(ProcessPendingDelivery::class)->handle(DeliveryId::fromString($deliveryId));
        } finally {
            $this->assertDatabaseHas('deliveries', ['public_id' => $deliveryId, 'status' => 'processing']);
            $this->assertDatabaseHas('delivery_attempts', ['attempt_number' => 1, 'status' => 'started']);
        }
    }

    public function test_attempts_are_read_only_and_unknown_deliveries_return_not_found(): void
    {
        $deliveryId = $this->createDelivery('https://receiver.example/readonly');

        $this->getJson('/api/deliveries/c4c3a03f-7e0a-4057-9145-09b056fa4526/attempts')
            ->assertNotFound()
            ->assertJsonPath('message', 'Delivery not found.');
        $this->postJson("/api/deliveries/{$deliveryId}/attempts", [])->assertMethodNotAllowed();
        $this->patchJson('/api/delivery-attempts/c4c3a03f-7e0a-4057-9145-09b056fa4526', [])->assertNotFound();
        $this->deleteJson('/api/delivery-attempts/c4c3a03f-7e0a-4057-9145-09b056fa4526')->assertNotFound();
    }

    private function createDelivery(string $url): string
    {
        $endpointId = (string) $this->postJson('/api/endpoints', [
            'name' => 'Webhook receiver',
            'url' => $url,
        ])->assertCreated()->json('data.id');
        $eventId = (string) $this->postJson('/api/events', [
            'type' => 'order.paid',
            'payload' => (object) [],
        ])->assertCreated()->json('data.id');

        return app(CreateDelivery::class)->handle($eventId, $endpointId)->id;
    }
}

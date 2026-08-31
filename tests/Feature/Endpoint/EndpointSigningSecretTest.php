<?php

declare(strict_types=1);

namespace Tests\Feature\Endpoint;

use App\Application\Clock\Clock;
use App\Application\Delivery\CreateDelivery;
use App\Application\Delivery\DeliveryTransport;
use App\Application\Delivery\ProcessPendingDelivery;
use App\Application\Delivery\WebhookRequest;
use App\Application\Delivery\WebhookResponse;
use App\Application\Delivery\WebhookTarget;
use App\Application\Delivery\WebhookTargetResolver;
use App\Application\Delivery\WebhookTransport;
use App\Domain\Delivery\DeliveryId;
use DateTimeImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FrozenClock;
use Tests\TestCase;

final class EndpointSigningSecretTest extends TestCase
{
    use RefreshDatabase;

    public function test_secret_is_revealed_once_encrypted_at_rest_and_never_leaks_from_endpoint_apis(): void
    {
        $endpointId = (string) $this->postJson('/api/endpoints', [
            'name' => 'Signed receiver', 'url' => 'https://receiver.example/webhook',
        ])->assertCreated()->json('data.id');

        $first = $this->postJson("/api/endpoints/{$endpointId}/signing-secret")
            ->assertCreated()
            ->assertJsonPath('data.version', 1)
            ->json('data');
        $secret = (string) $first['secret'];
        self::assertStringStartsWith('whsec_', $secret);

        $ciphertext = (string) DB::table('endpoint_signing_secrets')->value('encrypted_secret');
        self::assertNotSame($secret, $ciphertext);
        self::assertStringNotContainsString($secret, $ciphertext);

        $this->getJson('/api/endpoints')->assertOk()->assertJsonMissing(['secret' => $secret])->assertJsonMissing(['encrypted_secret' => $ciphertext]);
        $this->getJson("/api/endpoints/{$endpointId}")->assertOk()->assertJsonMissing(['secret' => $secret])->assertJsonMissing(['encrypted_secret' => $ciphertext]);
        $this->patchJson("/api/endpoints/{$endpointId}", ['name' => 'Renamed'])->assertOk()->assertJsonMissing(['secret' => $secret]);

        $second = $this->postJson("/api/endpoints/{$endpointId}/signing-secret")
            ->assertCreated()
            ->assertJsonPath('data.version', 2)
            ->json('data');
        self::assertNotSame($first['key_id'], $second['key_id']);
        self::assertNotSame($secret, $second['secret']);
        self::assertDatabaseCount('endpoint_signing_secrets', 2);
        self::assertDatabaseHas('endpoint_signing_secrets', ['public_id' => $first['key_id']]);
        self::assertDatabaseHas('endpoint_signing_secrets', ['public_id' => $second['key_id'], 'retired_at' => null]);
    }

    public function test_delivery_freezes_its_signing_key_and_signs_the_exact_transport_body(): void
    {
        $this->app->instance(Clock::class, new FrozenClock(new DateTimeImmutable('2026-09-01T12:00:00+00:00')));
        $endpointId = (string) $this->postJson('/api/endpoints', [
            'name' => 'Signed receiver', 'url' => 'https://receiver.example/webhook',
        ])->assertCreated()->json('data.id');
        $keyOne = $this->postJson("/api/endpoints/{$endpointId}/signing-secret")->assertCreated()->json('data');
        $eventId = (string) $this->postJson('/api/events', [
            'type' => 'order.paid', 'payload' => (object) ['meta' => (object) []],
        ])->assertCreated()->json('data.id');
        $deliveryId = app(CreateDelivery::class)->handle($eventId, $endpointId)->id;
        $keyTwo = $this->postJson("/api/endpoints/{$endpointId}/signing-secret")->assertCreated()->json('data');
        $duplicate = app(CreateDelivery::class)->handle($eventId, $endpointId);

        self::assertSame($deliveryId, $duplicate->id);
        self::assertSame(1, DB::table('deliveries')->where('public_id', $deliveryId)->count());

        $transport = new class implements WebhookTransport
        {
            public ?WebhookRequest $request = null;

            public function send(WebhookTarget $target, WebhookRequest $request): WebhookResponse
            {
                $this->request = $request;

                return new WebhookResponse(200, 3);
            }
        };
        $this->app->instance(WebhookTargetResolver::class, new class implements WebhookTargetResolver
        {
            public function resolve(string $targetUrl): WebhookTarget
            {
                return new WebhookTarget($targetUrl, 'receiver.example', 443, '1.1.1.1');
            }
        });
        $this->app->instance(WebhookTransport::class, $transport);

        app(ProcessPendingDelivery::class)->handle(DeliveryId::fromString($deliveryId));

        self::assertNotNull($transport->request);
        self::assertSame($keyOne['key_id'], $transport->request->headers['X-EventRelay-Signing-Key-Id']);
        self::assertSame('v1=', substr($transport->request->headers['X-EventRelay-Signature'], 0, 3));
        self::assertSame('1788264000', $transport->request->headers['X-EventRelay-Timestamp']);
        self::assertStringContainsString('"payload":{"meta":{}}', $transport->request->body);
        self::assertNotSame($keyTwo['key_id'], $transport->request->headers['X-EventRelay-Signing-Key-Id']);
        self::assertDatabaseHas('deliveries', ['public_id' => $deliveryId, 'status' => 'succeeded']);
    }

    public function test_a_retry_keeps_the_frozen_key_but_signs_with_its_new_attempt_and_timestamp(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-09-01T12:00:00+00:00'));
        $this->app->instance(Clock::class, $clock);
        $endpointId = (string) $this->postJson('/api/endpoints', [
            'name' => 'Retry signed receiver', 'url' => 'https://receiver.example/retry',
        ])->assertCreated()->json('data.id');
        $key = $this->postJson("/api/endpoints/{$endpointId}/signing-secret")->assertCreated()->json('data');
        $eventId = (string) $this->postJson('/api/events', [
            'type' => 'order.paid', 'payload' => (object) [],
        ])->assertCreated()->json('data.id');
        $deliveryId = app(CreateDelivery::class)->handle($eventId, $endpointId)->id;

        $queue = new class implements DeliveryTransport
        {
            public function publish(DeliveryId $deliveryId): void {}
        };
        $transport = new class implements WebhookTransport
        {
            /** @var list<WebhookRequest> */
            public array $requests = [];

            public function send(WebhookTarget $target, WebhookRequest $request): WebhookResponse
            {
                $this->requests[] = $request;

                return count($this->requests) === 1 ? new WebhookResponse(500, 1) : new WebhookResponse(200, 1);
            }
        };
        $this->bindTransport($transport);
        $this->app->instance(DeliveryTransport::class, $queue);

        app(ProcessPendingDelivery::class)->handle(DeliveryId::fromString($deliveryId));
        $clock->set(new DateTimeImmutable('2026-09-01T12:00:10+00:00'));
        app(ProcessPendingDelivery::class)->handle(DeliveryId::fromString($deliveryId));

        self::assertCount(2, $transport->requests);
        self::assertSame($key['key_id'], $transport->requests[0]->headers['X-EventRelay-Signing-Key-Id']);
        self::assertSame($key['key_id'], $transport->requests[1]->headers['X-EventRelay-Signing-Key-Id']);
        self::assertSame('1', $transport->requests[0]->headers['X-EventRelay-Attempt']);
        self::assertSame('2', $transport->requests[1]->headers['X-EventRelay-Attempt']);
        self::assertNotSame($transport->requests[0]->headers['X-EventRelay-Signature'], $transport->requests[1]->headers['X-EventRelay-Signature']);
        self::assertDatabaseHas('deliveries', ['public_id' => $deliveryId, 'status' => 'succeeded']);
    }

    public function test_corrupt_ciphertext_fails_closed_before_target_resolution_or_http(): void
    {
        $endpointId = (string) $this->postJson('/api/endpoints', [
            'name' => 'Corrupt key receiver', 'url' => 'https://receiver.example/corrupt',
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/endpoints/{$endpointId}/signing-secret")->assertCreated();
        $eventId = (string) $this->postJson('/api/events', [
            'type' => 'order.paid', 'payload' => (object) ['sensitive' => 'do-not-log'],
        ])->assertCreated()->json('data.id');
        $deliveryId = app(CreateDelivery::class)->handle($eventId, $endpointId)->id;
        DB::table('endpoint_signing_secrets')->update(['encrypted_secret' => 'corrupt-ciphertext']);
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
            public function resolve(string $targetUrl): WebhookTarget
            {
                throw new \LogicException('Target resolution must not run after signing cipher failure.');
            }
        });
        $this->app->instance(WebhookTransport::class, $transport);

        $this->expectException(DecryptException::class);
        try {
            app(ProcessPendingDelivery::class)->handle(DeliveryId::fromString($deliveryId));
        } finally {
            self::assertSame(0, $transport->calls);
            $this->assertDatabaseHas('deliveries', ['public_id' => $deliveryId, 'status' => 'processing']);
            $this->assertDatabaseHas('delivery_attempts', ['attempt_number' => 1, 'status' => 'started']);
        }
    }

    private function bindTransport(WebhookTransport $transport): void
    {
        $this->app->instance(WebhookTargetResolver::class, new class implements WebhookTargetResolver
        {
            public function resolve(string $targetUrl): WebhookTarget
            {
                return new WebhookTarget($targetUrl, 'receiver.example', 443, '1.1.1.1');
            }
        });
        $this->app->instance(WebhookTransport::class, $transport);
    }
}

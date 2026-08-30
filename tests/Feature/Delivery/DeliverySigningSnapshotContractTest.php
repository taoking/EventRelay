<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Application\Delivery\CreateDelivery;
use App\Application\Delivery\DeliveryRepository;
use App\Application\Delivery\DeliverySnapshotCreator;
use App\Domain\Delivery\Delivery;
use App\Domain\Endpoint\EndpointId;
use App\Domain\EndpointSigningSecret\EndpointSigningSecretId;
use App\Domain\Event\EventId;
use App\Infrastructure\Persistence\Eloquent\EloquentDeliveryRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use ReflectionClass;
use Tests\TestCase;

final class DeliverySigningSnapshotContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_delivery_distinguishes_explicit_unsigned_and_signed_endpoint_snapshots(): void
    {
        $unsignedEndpointId = $this->createEndpoint('Unsigned endpoint', 'https://unsigned.example/webhook');
        $unsignedEventId = $this->createEvent();

        $unsigned = app(CreateDelivery::class)->handle($unsignedEventId, $unsignedEndpointId);

        self::assertNull(app(DeliveryRepository::class)->find($unsigned->id)?->signingSecretId());
        $this->assertDatabaseHas('deliveries', ['public_id' => $unsigned->id, 'signing_secret_id' => null]);

        $signedEndpointId = $this->createEndpoint('Signed endpoint', 'https://signed.example/webhook');
        $keyId = (string) $this->postJson("/api/endpoints/{$signedEndpointId}/signing-secret")
            ->assertCreated()
            ->json('data.key_id');
        $signedEventId = $this->createEvent();

        $signed = app(CreateDelivery::class)->handle($signedEventId, $signedEndpointId);

        self::assertSame($keyId, app(DeliveryRepository::class)->find($signed->id)?->signingSecretId()?->toString());
        $this->assertDatabaseHas('deliveries', [
            'public_id' => $signed->id,
            'signing_secret_id' => DB::table('endpoint_signing_secrets')->where('public_id', $keyId)->value('id'),
        ]);
    }

    public function test_create_delivery_requires_the_snapshot_contract_without_an_optional_fallback(): void
    {
        $constructor = (new ReflectionClass(CreateDelivery::class))->getConstructor();

        self::assertNotNull($constructor);
        $parameters = $constructor->getParameters();
        self::assertCount(2, $parameters);
        self::assertSame(DeliverySnapshotCreator::class, $parameters[1]->getType()?->getName());
        self::assertFalse($parameters[1]->isOptional());
        self::assertInstanceOf(EloquentDeliveryRepository::class, app(DeliverySnapshotCreator::class));
        self::assertStringNotContainsString(
            'instanceof DeliverySnapshotCreator',
            (string) file_get_contents((new ReflectionClass(CreateDelivery::class))->getFileName()),
        );
    }

    public function test_repository_round_trips_a_signed_delivery_and_preserves_the_original_key_on_duplicate_create(): void
    {
        $endpointId = $this->createEndpoint('Repository signed endpoint', 'https://signed.example/repository');
        $keyOne = (string) $this->postJson("/api/endpoints/{$endpointId}/signing-secret")
            ->assertCreated()
            ->json('data.key_id');
        $eventId = $this->createEvent();
        $repository = app(DeliveryRepository::class);

        $created = $repository->createOrGet(Delivery::create(
            EventId::fromString($eventId),
            EndpointId::fromString($endpointId),
            'https://signed.example/repository',
            EndpointSigningSecretId::fromString($keyOne),
        ));

        self::assertSame($keyOne, $created->signingSecretId()?->toString());
        $this->assertDatabaseHas('deliveries', [
            'public_id' => $created->id()->toString(),
            'signing_secret_id' => DB::table('endpoint_signing_secrets')->where('public_id', $keyOne)->value('id'),
        ]);

        $keyTwo = (string) $this->postJson("/api/endpoints/{$endpointId}/signing-secret")
            ->assertCreated()
            ->json('data.key_id');
        $duplicate = $repository->createOrGet(Delivery::create(
            EventId::fromString($eventId),
            EndpointId::fromString($endpointId),
            'https://signed.example/repository',
            EndpointSigningSecretId::fromString($keyTwo),
        ));

        self::assertSame($created->id()->toString(), $duplicate->id()->toString());
        self::assertSame($keyOne, $duplicate->signingSecretId()?->toString());
        self::assertSame(1, DB::table('deliveries')->where('event_id', DB::table('events')->where('public_id', $eventId)->value('id'))->count());
    }

    public function test_repository_rejects_a_signing_secret_owned_by_another_endpoint(): void
    {
        $firstEndpointId = $this->createEndpoint('Key owner endpoint', 'https://first.example/webhook');
        $keyId = (string) $this->postJson("/api/endpoints/{$firstEndpointId}/signing-secret")
            ->assertCreated()
            ->json('data.key_id');
        $secondEndpointId = $this->createEndpoint('Other endpoint', 'https://second.example/webhook');
        $eventId = $this->createEvent();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('belongs to another endpoint');

        app(DeliveryRepository::class)->createOrGet(Delivery::create(
            EventId::fromString($eventId),
            EndpointId::fromString($secondEndpointId),
            'https://second.example/webhook',
            EndpointSigningSecretId::fromString($keyId),
        ));
    }

    private function createEndpoint(string $name, string $url): string
    {
        return (string) $this->postJson('/api/endpoints', ['name' => $name, 'url' => $url])
            ->assertCreated()
            ->json('data.id');
    }

    private function createEvent(): string
    {
        return (string) $this->postJson('/api/events', [
            'type' => 'order.paid',
            'payload' => (object) [],
        ])->assertCreated()->json('data.id');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Delivery;

use App\Application\Delivery\DeliveryNotFound;
use App\Application\Delivery\DeliveryRepository;
use App\Application\Delivery\ProcessPendingDelivery;
use App\Domain\Delivery\Delivery;
use App\Domain\Delivery\DeliveryId;
use App\Domain\Endpoint\EndpointId;
use App\Domain\Event\EventId;
use Tests\TestCase;

final class ProcessPendingDeliveryTest extends TestCase
{
    public function test_it_reads_an_existing_pending_delivery_without_changing_it(): void
    {
        $delivery = Delivery::create(
            EventId::fromString('7db4d301-f44a-4dab-a545-6f9046cbeb6f'),
            EndpointId::fromString('8db4d301-f44a-4dab-a545-6f9046cbeb6f'),
        );

        (new ProcessPendingDelivery(
            new class($delivery) implements DeliveryRepository
            {
                public function __construct(
                    private Delivery $delivery,
                ) {}

                public function createOrGet(Delivery $delivery): Delivery
                {
                    return $delivery;
                }

                public function all(): array
                {
                    return [$this->delivery];
                }

                public function find(string $id): ?Delivery
                {
                    return $id === $this->delivery->id()->toString() ? $this->delivery : null;
                }
            },
        ))->handle($delivery->id());

        self::assertSame('pending', $delivery->status()->value);
    }

    public function test_it_rejects_a_missing_delivery(): void
    {
        $processor = new ProcessPendingDelivery(
            new class implements DeliveryRepository
            {
                public function createOrGet(Delivery $delivery): Delivery
                {
                    return $delivery;
                }

                public function all(): array
                {
                    return [];
                }

                public function find(string $id): ?Delivery
                {
                    return null;
                }
            },
        );

        $this->expectException(DeliveryNotFound::class);

        $processor->handle(DeliveryId::fromString('9db4d301-f44a-4dab-a545-6f9046cbeb6f'));
    }
}

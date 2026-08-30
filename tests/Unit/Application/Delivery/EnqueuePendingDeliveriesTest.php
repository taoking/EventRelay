<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Delivery;

use App\Application\Delivery\DeliveryQueue;
use App\Application\Delivery\DeliveryQueueUnavailable;
use App\Application\Delivery\EnqueuePendingDeliveries;
use App\Application\Delivery\PendingDeliveryFinder;
use App\Domain\Delivery\DeliveryId;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class EnqueuePendingDeliveriesTest extends TestCase
{
    public function test_it_enqueues_pending_delivery_ids_in_finder_order_and_counts_known_publication_failures(): void
    {
        $first = DeliveryId::fromString('2db4d301-f44a-4dab-a545-6f9046cbeb6f');
        $second = DeliveryId::fromString('3db4d301-f44a-4dab-a545-6f9046cbeb6f');
        $third = DeliveryId::fromString('4db4d301-f44a-4dab-a545-6f9046cbeb6f');
        $enqueued = [];

        $result = new EnqueuePendingDeliveries(
            new class($first, $second, $third) implements PendingDeliveryFinder
            {
                public function __construct(
                    private DeliveryId $first,
                    private DeliveryId $second,
                    private DeliveryId $third,
                ) {}

                public function findPending(int $limit): array
                {
                    return [$this->first, $this->second, $this->third];
                }
            },
            new class($second, $enqueued) implements DeliveryQueue
            {
                /**
                 * @param  list<string>  $enqueued
                 */
                public function __construct(
                    private DeliveryId $unavailable,
                    array &$enqueued,
                ) {
                    $this->enqueued = &$enqueued;
                }

                /**
                 * @var list<string>
                 */
                private array $enqueued;

                public function enqueue(DeliveryId $deliveryId): void
                {
                    if ($deliveryId->toString() === $this->unavailable->toString()) {
                        throw new DeliveryQueueUnavailable($deliveryId, new RuntimeException('Redis is unavailable.'));
                    }

                    $this->enqueued[] = $deliveryId->toString();
                }

                public function schedule(DeliveryId $deliveryId, \DateTimeImmutable $availableAt): void {}
            },
        )->handle(3);

        self::assertSame(2, $result->enqueued);
        self::assertSame(1, $result->failed);
        self::assertSame([$first->toString(), $third->toString()], $enqueued);
    }

    public function test_it_rejects_limits_outside_the_bounded_recovery_range(): void
    {
        $useCase = new EnqueuePendingDeliveries(
            new class implements PendingDeliveryFinder
            {
                public function findPending(int $limit): array
                {
                    return [];
                }
            },
            new class implements DeliveryQueue
            {
                public function enqueue(DeliveryId $deliveryId): void {}

                public function schedule(DeliveryId $deliveryId, \DateTimeImmutable $availableAt): void {}
            },
        );

        $this->expectException(InvalidArgumentException::class);

        $useCase->handle(0);
    }
}

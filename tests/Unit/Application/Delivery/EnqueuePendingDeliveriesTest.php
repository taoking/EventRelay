<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Delivery;

use App\Application\Clock\Clock;
use App\Application\Delivery\DeliveryExecutionIntent;
use App\Application\Delivery\DeliveryOutboxIntentFinder;
use App\Application\Delivery\DeliveryOutboxRecovery;
use App\Application\Delivery\EnqueuePendingDeliveries;
use App\Domain\Delivery\DeliveryId;
use InvalidArgumentException;
use Tests\TestCase;

final class EnqueuePendingDeliveriesTest extends TestCase
{
    public function test_it_ensures_pending_delivery_execution_intents_in_finder_order(): void
    {
        $first = DeliveryId::fromString('2db4d301-f44a-4dab-a545-6f9046cbeb6f');
        $second = DeliveryId::fromString('3db4d301-f44a-4dab-a545-6f9046cbeb6f');
        $third = DeliveryId::fromString('4db4d301-f44a-4dab-a545-6f9046cbeb6f');
        $ensured = [];

        $result = new EnqueuePendingDeliveries(
            new class($first, $second, $third) implements DeliveryOutboxIntentFinder
            {
                public function __construct(
                    private DeliveryId $first,
                    private DeliveryId $second,
                    private DeliveryId $third,
                ) {}

                public function findPendingInitial(int $limit): array
                {
                    return [
                        new DeliveryExecutionIntent($this->first, 1, null),
                        new DeliveryExecutionIntent($this->second, 1, null),
                        new DeliveryExecutionIntent($this->third, 1, null),
                    ];
                }

                public function findDueRetries(\DateTimeImmutable $now, int $limit): array
                {
                    return [];
                }
            },
            new class($ensured) implements DeliveryOutboxRecovery
            {
                /** @param list<string> $ensured */
                public function __construct(array &$ensured)
                {
                    $this->ensured = &$ensured;
                }

                /** @var list<string> */
                private array $ensured;

                public function ensureRecoverable(DeliveryExecutionIntent $intent, \DateTimeImmutable $now): bool
                {
                    $this->ensured[] = $intent->deliveryId->toString();

                    return true;
                }
            },
            new class implements Clock
            {
                public function now(): \DateTimeImmutable
                {
                    return new \DateTimeImmutable('2026-09-02T12:00:00+00:00');
                }
            },
        )->handle(3);

        self::assertSame(3, $result->ensured);
        self::assertSame([$first->toString(), $second->toString(), $third->toString()], $ensured);
    }

    public function test_it_rejects_limits_outside_the_bounded_recovery_range(): void
    {
        $useCase = new EnqueuePendingDeliveries(
            new class implements DeliveryOutboxIntentFinder
            {
                public function findPendingInitial(int $limit): array
                {
                    return [];
                }

                public function findDueRetries(\DateTimeImmutable $now, int $limit): array
                {
                    return [];
                }
            },
            new class implements DeliveryOutboxRecovery
            {
                public function ensureRecoverable(DeliveryExecutionIntent $intent, \DateTimeImmutable $now): bool
                {
                    return false;
                }
            },
            new class implements Clock
            {
                public function now(): \DateTimeImmutable
                {
                    return new \DateTimeImmutable('2026-09-02T12:00:00+00:00');
                }
            },
        );

        $this->expectException(InvalidArgumentException::class);

        $useCase->handle(0);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Application\Delivery\DeliveryQueue;
use App\Application\Delivery\EnqueuePendingDeliveries;
use App\Application\Delivery\PendingDeliveryFinder;
use App\Domain\Delivery\DeliveryId;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class EnqueuePendingDeliveriesCommandTest extends TestCase
{
    public function test_the_command_uses_the_application_recovery_use_case_with_the_requested_limit(): void
    {
        $first = DeliveryId::fromString('5db4d301-f44a-4dab-a545-6f9046cbeb6f');
        $second = DeliveryId::fromString('6db4d301-f44a-4dab-a545-6f9046cbeb6f');
        $limits = [];
        $queued = [];

        $this->app->instance(
            EnqueuePendingDeliveries::class,
            new EnqueuePendingDeliveries(
                new class($first, $second, $limits) implements PendingDeliveryFinder
                {
                    /**
                     * @param  list<int>  $limits
                     */
                    public function __construct(
                        private DeliveryId $first,
                        private DeliveryId $second,
                        array &$limits,
                    ) {
                        $this->limits = &$limits;
                    }

                    /**
                     * @var list<int>
                     */
                    private array $limits;

                    public function findPending(int $limit): array
                    {
                        $this->limits[] = $limit;

                        return [$this->first, $this->second];
                    }
                },
                new class($queued) implements DeliveryQueue
                {
                    /**
                     * @param  list<string>  $queued
                     */
                    public function __construct(array &$queued)
                    {
                        $this->queued = &$queued;
                    }

                    /**
                     * @var list<string>
                     */
                    private array $queued;

                    public function enqueue(DeliveryId $deliveryId): void
                    {
                        $this->queued[] = $deliveryId->toString();
                    }
                },
            ),
        );

        self::assertSame(0, Artisan::call('deliveries:enqueue-pending', ['--limit' => '2']));
        self::assertSame([2], $limits);
        self::assertSame([$first->toString(), $second->toString()], $queued);
        self::assertStringContainsString('成功 2，发布失败 0', Artisan::output());
    }

    public function test_the_command_rejects_invalid_recovery_limits(): void
    {
        self::assertSame(1, Artisan::call('deliveries:enqueue-pending', ['--limit' => '0']));
        self::assertStringContainsString('between 1 and 1000', Artisan::output());

        self::assertSame(1, Artisan::call('deliveries:enqueue-pending', ['--limit' => '1001']));
        self::assertStringContainsString('between 1 and 1000', Artisan::output());
    }
}

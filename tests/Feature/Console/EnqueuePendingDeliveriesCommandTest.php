<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Application\Clock\Clock;
use App\Application\Delivery\DeliveryExecutionIntent;
use App\Application\Delivery\DeliveryOutboxIntentFinder;
use App\Application\Delivery\DeliveryOutboxWriter;
use App\Application\Delivery\EnqueuePendingDeliveries;
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
        $ensured = [];

        $this->app->instance(
            EnqueuePendingDeliveries::class,
            new EnqueuePendingDeliveries(
                new class($first, $second, $limits) implements DeliveryOutboxIntentFinder
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

                    public function findPendingInitial(int $limit): array
                    {
                        $this->limits[] = $limit;

                        return [
                            new DeliveryExecutionIntent($this->first, 1, null),
                            new DeliveryExecutionIntent($this->second, 1, null),
                        ];
                    }

                    public function findDueRetries(\DateTimeImmutable $now, int $limit): array
                    {
                        return [];
                    }
                },
                new class($ensured) implements DeliveryOutboxWriter
                {
                    /**
                     * @param  list<string>  $ensured
                     */
                    public function __construct(array &$ensured)
                    {
                        $this->ensured = &$ensured;
                    }

                    /**
                     * @var list<string>
                     */
                    private array $ensured;

                    public function schedule(DeliveryExecutionIntent $intent, \DateTimeImmutable $now): void
                    {
                        $this->ensured[] = $intent->deliveryId->toString();
                    }
                },
                new class implements Clock
                {
                    public function now(): \DateTimeImmutable
                    {
                        return new \DateTimeImmutable('2026-09-02T12:00:00+00:00');
                    }
                },
            ),
        );

        self::assertSame(0, Artisan::call('deliveries:enqueue-pending', ['--limit' => '2']));
        self::assertSame([2], $limits);
        self::assertSame([$first->toString(), $second->toString()], $ensured);
        self::assertStringContainsString('处理 2', Artisan::output());
    }

    public function test_the_command_rejects_invalid_recovery_limits(): void
    {
        self::assertSame(1, Artisan::call('deliveries:enqueue-pending', ['--limit' => '0']));
        self::assertStringContainsString('between 1 and 1000', Artisan::output());

        self::assertSame(1, Artisan::call('deliveries:enqueue-pending', ['--limit' => '1001']));
        self::assertStringContainsString('between 1 and 1000', Artisan::output());
    }
}

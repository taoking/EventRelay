<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Application\Clock\Clock;
use App\Application\Delivery\DeliveryOutboxPublisherRepository;
use App\Application\Delivery\DeliveryTransport;
use App\Application\Delivery\PublishDeliveryOutbox;
use App\Domain\Delivery\DeliveryId;
use DateTimeImmutable;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class WorkDeliveryOutboxCommandTest extends TestCase
{
    public function test_once_runs_exactly_one_bounded_application_publication_cycle(): void
    {
        $limits = [];
        $this->app->instance(PublishDeliveryOutbox::class, new PublishDeliveryOutbox(
            new class($limits) implements DeliveryOutboxPublisherRepository
            {
                /** @param list<int> $limits */
                public function __construct(private array &$limits) {}

                public function claim(int $limit, DateTimeImmutable $now, DateTimeImmutable $leaseUntil): array
                {
                    $this->limits[] = $limit;

                    return [];
                }

                public function markPublished(string $publicId, string $claimToken, DateTimeImmutable $now): bool
                {
                    return false;
                }

                public function releaseAfterKnownPublicationFailure(string $publicId, string $claimToken, string $transport, DateTimeImmutable $now): bool
                {
                    return false;
                }
            },
            new class implements DeliveryTransport
            {
                public function publish(DeliveryId $deliveryId): void {}
            },
            new class implements Clock
            {
                public function now(): DateTimeImmutable
                {
                    return new DateTimeImmutable('2026-09-10T12:00:00+00:00');
                }
            },
        ));

        self::assertSame(0, Artisan::call('outbox:work', ['--limit' => '2', '--once' => true]));
        self::assertSame([2], $limits);
        self::assertStringContainsString('工作轮次完成', Artisan::output());
    }

    public function test_invalid_sleep_fails_before_starting_a_worker_loop(): void
    {
        self::assertSame(1, Artisan::call('outbox:work', ['--sleep' => '0', '--once' => true]));
        self::assertStringContainsString('sleep 必须是 1 到 60', Artisan::output());
    }
}

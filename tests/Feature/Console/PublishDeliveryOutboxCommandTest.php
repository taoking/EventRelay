<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Application\Clock\Clock;
use App\Application\Delivery\ClaimedDeliveryOutboxMessage;
use App\Application\Delivery\DeliveryExecutionIntent;
use App\Application\Delivery\DeliveryOutboxPublisherRepository;
use App\Application\Delivery\DeliveryTransport;
use App\Application\Delivery\PublishDeliveryOutbox;
use App\Domain\Delivery\DeliveryId;
use DateTimeImmutable;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class PublishDeliveryOutboxCommandTest extends TestCase
{
    public function test_command_delegates_the_requested_bounded_limit_to_the_application_publisher(): void
    {
        $limits = [];
        $this->app->instance(PublishDeliveryOutbox::class, new PublishDeliveryOutbox(
            new class($limits) implements DeliveryOutboxPublisherRepository
            {
                /** @param list<int> $limits */
                public function __construct(array &$limits)
                {
                    $this->limits = &$limits;
                }

                /** @var list<int> */
                private array $limits;

                public function claim(int $limit, DateTimeImmutable $now, DateTimeImmutable $leaseUntil): array
                {
                    $this->limits[] = $limit;

                    return [new ClaimedDeliveryOutboxMessage(
                        '0db4d301-f44a-4dab-a545-6f9046cbeb6f',
                        new DeliveryExecutionIntent(DeliveryId::fromString('1db4d301-f44a-4dab-a545-6f9046cbeb6f'), 1, null),
                        '2db4d301-f44a-4dab-a545-6f9046cbeb6f',
                    )];
                }

                public function markPublished(string $publicId, string $claimToken, DateTimeImmutable $now): bool
                {
                    return true;
                }

                public function releaseAfterKnownPublicationFailure(string $publicId, string $claimToken, string $transport, DateTimeImmutable $now): bool
                {
                    return true;
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
                    return new DateTimeImmutable('2026-09-02T12:00:00+00:00');
                }
            },
        ));

        self::assertSame(0, Artisan::call('outbox:publish', ['--limit' => '2']));
        self::assertSame([2], $limits);
        self::assertStringContainsString('成功 1', Artisan::output());
    }

    public function test_command_rejects_invalid_limits_before_calling_the_publisher(): void
    {
        self::assertSame(1, Artisan::call('outbox:publish', ['--limit' => '0']));
        self::assertStringContainsString('between 1 and 1000', Artisan::output());

        self::assertSame(1, Artisan::call('outbox:publish', ['--limit' => '1001']));
        self::assertStringContainsString('between 1 and 1000', Artisan::output());
    }
}

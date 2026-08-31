<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Application\Clock\Clock;
use App\Application\Delivery\DeliveryOutboxPublisherRepository;
use App\Application\Delivery\DeliveryTransport;
use App\Application\Delivery\PublishDeliveryOutbox;
use App\Domain\Delivery\DeliveryId;
use App\Infrastructure\Console\OutboxWorkerSleeper;
use Closure;
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

    public function test_invalid_limit_fails_before_starting_a_worker_loop(): void
    {
        self::assertSame(1, Artisan::call('outbox:work', ['--limit' => '1001', '--once' => true]));
        self::assertStringContainsString('limit 必须是 1 到 1000', Artisan::output());
    }

    public function test_unknown_publisher_exception_propagates_instead_of_becoming_a_successful_worker_cycle(): void
    {
        $this->app->instance(PublishDeliveryOutbox::class, $this->publisher(static function (): void {
            throw new \LogicException('Unexpected publisher error.');
        }));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Unexpected publisher error.');
        Artisan::call('outbox:work', ['--once' => true]);
    }

    public function test_sigterm_during_idle_sleep_does_not_start_a_second_publication_cycle(): void
    {
        $this->requireProcessControl();
        $callsFile = tempnam(sys_get_temp_dir(), 'eventrelay-outbox-idle-cycles-');
        self::assertNotFalse($callsFile);
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$parent, $child] = $pair;
        $pid = pcntl_fork();

        if ($pid === -1) {
            self::fail('Unable to fork the idle outbox worker.');
        }
        if ($pid === 0) {
            fclose($parent);
            $this->runIdleWorker($child, $callsFile);
        }

        fclose($child);
        stream_set_timeout($parent, 10);

        try {
            self::assertSame("idle-sleep\n", fgets($parent));
            self::assertTrue(posix_kill($pid, SIGTERM));
            fwrite($parent, "release\n");
            self::assertSame("exit:0\n", fgets($parent));
            pcntl_waitpid($pid, $status);
            self::assertSame(0, pcntl_wexitstatus($status));
            self::assertSame(['cycle'], file($callsFile, FILE_IGNORE_NEW_LINES));
        } finally {
            fclose($parent);
            unlink($callsFile);
        }
    }

    public function test_sigterm_during_the_current_publication_cycle_allows_that_cycle_to_finish_without_starting_another(): void
    {
        $this->requireProcessControl();
        $callsFile = tempnam(sys_get_temp_dir(), 'eventrelay-outbox-batch-cycles-');
        self::assertNotFalse($callsFile);
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$parent, $child] = $pair;
        $pid = pcntl_fork();

        if ($pid === -1) {
            self::fail('Unable to fork the current batch outbox worker.');
        }
        if ($pid === 0) {
            fclose($parent);
            $this->runCurrentBatchWorker($child, $callsFile);
        }

        fclose($child);
        stream_set_timeout($parent, 10);

        try {
            self::assertSame("entered-batch\n", fgets($parent));
            self::assertTrue(posix_kill($pid, SIGTERM));
            fwrite($parent, "release\n");
            self::assertSame("exit:0\n", fgets($parent));
            pcntl_waitpid($pid, $status);
            self::assertSame(0, pcntl_wexitstatus($status));
            self::assertSame(['cycle'], file($callsFile, FILE_IGNORE_NEW_LINES));
        } finally {
            fclose($parent);
            unlink($callsFile);
        }
    }

    /** @return never-return */
    private function runIdleWorker(mixed $socket, string $callsFile): void
    {
        try {
            $this->app->instance(PublishDeliveryOutbox::class, $this->publisher(static function () use ($callsFile): void {
                file_put_contents($callsFile, "cycle\n", FILE_APPEND | LOCK_EX);
            }));
            $this->app->instance(OutboxWorkerSleeper::class, new class($socket) implements OutboxWorkerSleeper
            {
                public function __construct(private readonly mixed $socket) {}

                public function sleep(int $seconds): void
                {
                    fwrite($this->socket, "idle-sleep\n");
                    if (fgets($this->socket) !== "release\n") {
                        throw new \LogicException('The idle worker did not receive its release barrier.');
                    }
                }
            });
            $exit = Artisan::call('outbox:work', ['--sleep' => '30']);
            fwrite($socket, "exit:{$exit}\n");
            fclose($socket);
            exit($exit);
        } catch (\Throwable $exception) {
            fwrite($socket, 'error:'.$exception::class."\n");
            fclose($socket);
            exit(1);
        }
    }

    /** @return never-return */
    private function runCurrentBatchWorker(mixed $socket, string $callsFile): void
    {
        try {
            $this->app->instance(PublishDeliveryOutbox::class, $this->publisher(static function () use ($callsFile, $socket): void {
                file_put_contents($callsFile, "cycle\n", FILE_APPEND | LOCK_EX);
                fwrite($socket, "entered-batch\n");
                if (fgets($socket) !== "release\n") {
                    throw new \LogicException('The batch worker did not receive its release barrier.');
                }
            }));
            $exit = Artisan::call('outbox:work', ['--sleep' => '30']);
            fwrite($socket, "exit:{$exit}\n");
            fclose($socket);
            exit($exit);
        } catch (\Throwable $exception) {
            fwrite($socket, 'error:'.$exception::class."\n");
            fclose($socket);
            exit(1);
        }
    }

    private function requireProcessControl(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('This regression test requires pcntl and posix process control.');
        }
    }

    private function publisher(Closure $onClaim): PublishDeliveryOutbox
    {
        return new PublishDeliveryOutbox(
            new class($onClaim) implements DeliveryOutboxPublisherRepository
            {
                public function __construct(private readonly Closure $onClaim) {}

                public function claim(int $limit, DateTimeImmutable $now, DateTimeImmutable $leaseUntil): array
                {
                    ($this->onClaim)();

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
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Application\Clock\Clock;
use App\Application\Delivery\CreateDelivery;
use App\Application\Delivery\DeliveryExecutionConflict;
use App\Application\Delivery\DeliveryQueue;
use App\Application\Delivery\ProcessPendingDelivery;
use App\Application\Delivery\RecoverStaleDelivery;
use App\Application\Delivery\WebhookRequest;
use App\Application\Delivery\WebhookResponse;
use App\Application\Delivery\WebhookTarget;
use App\Application\Delivery\WebhookTargetResolver;
use App\Application\Delivery\WebhookTransport;
use App\Domain\Delivery\DeliveryId;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Support\FrozenClock;
use Tests\TestCase;

final class StaleRecoveryLateFinalizeConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_recovery_commits_before_the_real_worker_late_finalize_and_preserves_the_stale_state(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('This regression test requires MySQL/InnoDB and pcntl.');
        }

        $startedAt = new DateTimeImmutable('2026-08-31T12:00:00+00:00');
        $recoveryAt = $startedAt->modify('+60 seconds');
        $deliveryId = $this->createPendingDelivery();
        $callsFile = tempnam(sys_get_temp_dir(), 'eventrelay-stale-late-call-');
        $scheduledFile = tempnam(sys_get_temp_dir(), 'eventrelay-stale-late-schedule-');
        self::assertNotFalse($callsFile);
        self::assertNotFalse($scheduledFile);
        $workerPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($workerPair);
        [$workerParent, $workerChild] = $workerPair;

        $workerPid = pcntl_fork();
        if ($workerPid === -1) {
            self::fail('Unable to fork the delivery worker.');
        }
        if ($workerPid === 0) {
            fclose($workerParent);
            $this->runWorker($deliveryId, $startedAt, $workerChild, $callsFile);
        }

        fclose($workerChild);
        stream_set_timeout($workerParent, 10);

        try {
            self::assertSame("entered-transport\n", fgets($workerParent));

            $recoveryPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            self::assertNotFalse($recoveryPair);
            [$recoveryParent, $recoveryChild] = $recoveryPair;
            $recoveryPid = pcntl_fork();
            if ($recoveryPid === -1) {
                self::fail('Unable to fork the stale recovery worker.');
            }
            if ($recoveryPid === 0) {
                fclose($recoveryParent);
                $this->runRecovery($deliveryId, $recoveryAt, $recoveryChild, $scheduledFile);
            }

            fclose($recoveryChild);
            stream_set_timeout($recoveryParent, 10);
            self::assertSame("recovery-committed\n", fgets($recoveryParent));
            pcntl_waitpid($recoveryPid, $recoveryStatus);
            self::assertSame(0, pcntl_wexitstatus($recoveryStatus));
            fclose($recoveryParent);

            fwrite($workerParent, "release\n");
            self::assertSame("late-finalize-conflict\n", fgets($workerParent));
            pcntl_waitpid($workerPid, $workerStatus);
            self::assertSame(0, pcntl_wexitstatus($workerStatus));

            $this->reconnectAfterFork();
            self::assertSame(['called'], file($callsFile, FILE_IGNORE_NEW_LINES));
            self::assertSame([$deliveryId], file($scheduledFile, FILE_IGNORE_NEW_LINES));
            $this->assertDatabaseHas('deliveries', [
                'public_id' => $deliveryId,
                'status' => 'retry_scheduled',
            ]);
            self::assertNotNull(DB::table('deliveries')->where('public_id', $deliveryId)->value('next_attempt_at'));
            $this->assertDatabaseHas('delivery_attempts', [
                'attempt_number' => 1,
                'status' => 'abandoned',
                'failure_type' => 'stale_processing',
            ]);
            self::assertSame(1, DB::table('delivery_attempts')->count());
        } finally {
            fclose($workerParent);
            unlink($callsFile);
            unlink($scheduledFile);
        }
    }

    /** @return never-return */
    private function runWorker(string $deliveryId, DateTimeImmutable $startedAt, mixed $socket, string $callsFile): void
    {
        try {
            $this->reconnectAfterFork();
            app()->instance(Clock::class, new FrozenClock($startedAt));
            $this->bindTargetResolver();
            app()->instance(WebhookTransport::class, new class($socket, $callsFile) implements WebhookTransport
            {
                public function __construct(
                    private readonly mixed $socket,
                    private readonly string $callsFile,
                ) {}

                public function send(WebhookTarget $target, WebhookRequest $request): WebhookResponse
                {
                    file_put_contents($this->callsFile, "called\n", FILE_APPEND | LOCK_EX);
                    fwrite($this->socket, "entered-transport\n");

                    if (fgets($this->socket) !== "release\n") {
                        throw new \LogicException('Worker did not receive the test barrier release.');
                    }

                    return new WebhookResponse(200, 1);
                }
            });
            app(ProcessPendingDelivery::class)->handle(DeliveryId::fromString($deliveryId));
            fwrite($socket, "unexpected-success\n");
            fclose($socket);
            exit(1);
        } catch (DeliveryExecutionConflict) {
            fwrite($socket, "late-finalize-conflict\n");
            fclose($socket);
            exit(0);
        } catch (\Throwable $exception) {
            fwrite($socket, 'error:'.get_class($exception)."\n");
            fclose($socket);
            exit(1);
        }
    }

    /** @return never-return */
    private function runRecovery(string $deliveryId, DateTimeImmutable $recoveryAt, mixed $socket, string $scheduledFile): void
    {
        try {
            $this->reconnectAfterFork();
            app()->instance(Clock::class, new FrozenClock($recoveryAt));
            app()->instance(DeliveryQueue::class, new class($scheduledFile) implements DeliveryQueue
            {
                public function __construct(private readonly string $scheduledFile) {}

                public function enqueue(DeliveryId $deliveryId): void {}

                public function schedule(DeliveryId $deliveryId, DateTimeImmutable $availableAt): void
                {
                    file_put_contents($this->scheduledFile, $deliveryId->toString()."\n", FILE_APPEND | LOCK_EX);
                }
            });
            $result = app(RecoverStaleDelivery::class)->handle(DeliveryId::fromString($deliveryId));

            if ($result === null) {
                throw new \LogicException('The started attempt should be stale at the recovery threshold.');
            }

            fwrite($socket, "recovery-committed\n");
            fclose($socket);
            exit(0);
        } catch (\Throwable $exception) {
            fwrite($socket, 'error:'.get_class($exception)."\n");
            fclose($socket);
            exit(1);
        }
    }

    private function bindTargetResolver(): void
    {
        app()->instance(WebhookTargetResolver::class, new class implements WebhookTargetResolver
        {
            public function resolve(string $url): WebhookTarget
            {
                return new WebhookTarget($url, 'receiver.example', 443, '1.1.1.1');
            }
        });
    }

    private function reconnectAfterFork(): void
    {
        DB::disconnect();
        DB::purge();
        DB::connection()->getPdo();
    }

    private function createPendingDelivery(): string
    {
        $endpointId = (string) $this->postJson('/api/endpoints', [
            'name' => 'Stale late finalize receiver',
            'url' => 'https://receiver.example/stale-late-finalize',
        ])->assertCreated()->json('data.id');
        $eventId = (string) $this->postJson('/api/events', [
            'type' => 'order.paid',
            'payload' => (object) [],
        ])->assertCreated()->json('data.id');

        return app(CreateDelivery::class)->handle($eventId, $endpointId)->id;
    }
}

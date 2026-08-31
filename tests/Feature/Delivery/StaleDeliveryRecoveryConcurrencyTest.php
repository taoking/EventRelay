<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Application\Clock\Clock;
use App\Application\Delivery\CreateDelivery;
use App\Application\Delivery\DeliveryExecutionRepository;
use App\Application\Delivery\RecoverStaleDelivery;
use App\Domain\Delivery\DeliveryId;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Support\FrozenClock;
use Tests\TestCase;

final class StaleDeliveryRecoveryConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_independent_stale_recoverers_abandon_one_attempt_and_schedule_once(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('This regression test requires MySQL/InnoDB and pcntl.');
        }

        $now = new DateTimeImmutable('2026-08-31T12:00:00+00:00');
        $clock = new FrozenClock($now);
        $this->app->instance(Clock::class, $clock);
        $deliveryId = $this->createProcessingDelivery($now->modify('-60 seconds'));
        $firstPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $secondPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($firstPair);
        self::assertNotFalse($secondPair);
        [$firstParent, $firstChild] = $firstPair;
        [$secondParent, $secondChild] = $secondPair;

        $firstPid = pcntl_fork();
        if ($firstPid === -1) {
            self::fail('Unable to fork the first stale recoverer.');
        }
        if ($firstPid === 0) {
            fclose($firstParent);
            $this->recoverInChild($deliveryId, $firstChild);
        }

        $secondPid = pcntl_fork();
        if ($secondPid === -1) {
            self::fail('Unable to fork the second stale recoverer.');
        }
        if ($secondPid === 0) {
            fclose($secondParent);
            $this->recoverInChild($deliveryId, $secondChild);
        }

        fclose($firstChild);
        fclose($secondChild);
        stream_set_timeout($firstParent, 10);
        stream_set_timeout($secondParent, 10);

        try {
            $results = [trim((string) fgets($firstParent)), trim((string) fgets($secondParent))];
            pcntl_waitpid($firstPid, $firstStatus);
            pcntl_waitpid($secondPid, $secondStatus);
            self::assertSame(0, pcntl_wexitstatus($firstStatus));
            self::assertSame(0, pcntl_wexitstatus($secondStatus));
            self::assertCount(1, array_filter($results, static fn (string $result): bool => $result === 'recovered'));
            self::assertCount(1, array_filter($results, static fn (string $result): bool => $result === 'noop'));

            $this->reconnectAfterFork();
            $this->assertDatabaseHas('deliveries', ['public_id' => $deliveryId, 'status' => 'retry_scheduled']);
            $this->assertDatabaseHas('delivery_attempts', ['attempt_number' => 1, 'status' => 'abandoned']);
            $this->assertDatabaseHas('delivery_outbox_messages', [
                'dedupe_key' => "delivery:{$deliveryId}:attempt:2",
                'attempt_number' => 2,
                'status' => 'pending',
            ]);
        } finally {
            fclose($firstParent);
            fclose($secondParent);
        }
    }

    /** @return never-return */
    private function recoverInChild(string $deliveryId, mixed $socket): void
    {
        try {
            $this->reconnectAfterFork();
            $result = app(RecoverStaleDelivery::class)->handle(DeliveryId::fromString($deliveryId));
            fwrite($socket, $result === null ? "noop\n" : "recovered\n");
            fclose($socket);
            exit(0);
        } catch (\Throwable $exception) {
            fwrite($socket, 'error:'.get_class($exception)."\n");
            fclose($socket);
            exit(1);
        }
    }

    private function reconnectAfterFork(): void
    {
        DB::disconnect();
        DB::purge();
        DB::connection()->getPdo();
    }

    private function createProcessingDelivery(DateTimeImmutable $startedAt): string
    {
        $endpointId = (string) $this->postJson('/api/endpoints', [
            'name' => 'Stale concurrency receiver',
            'url' => 'https://receiver.example/stale-concurrency',
        ])->assertCreated()->json('data.id');
        $eventId = (string) $this->postJson('/api/events', [
            'type' => 'order.paid',
            'payload' => (object) [],
        ])->assertCreated()->json('data.id');
        $deliveryId = app(CreateDelivery::class)->handle($eventId, $endpointId)->id;
        app(DeliveryExecutionRepository::class)->claim(DeliveryId::fromString($deliveryId), $startedAt);

        return $deliveryId;
    }
}

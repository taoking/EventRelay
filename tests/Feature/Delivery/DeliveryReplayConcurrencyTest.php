<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Application\Delivery\CreateDelivery;
use App\Application\Delivery\ReplayFailedDelivery;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DeliveryReplayConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_mysql_processes_with_the_same_idempotency_key_create_one_replay_and_one_outbox_intent(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('This regression test requires MySQL/InnoDB and pcntl.');
        }

        $sourceId = $this->failedSource();
        [$parentA, $childA] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        [$parentB, $childB] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($parentA);
        self::assertNotFalse($childA);
        self::assertNotFalse($parentB);
        self::assertNotFalse($childB);

        $pidA = pcntl_fork();
        if ($pidA === -1) {
            self::fail('Unable to fork replay process A.');
        }
        if ($pidA === 0) {
            fclose($parentA);
            fclose($parentB);
            fclose($childB);
            $this->runReplay($sourceId, $childA);
        }

        $pidB = pcntl_fork();
        if ($pidB === -1) {
            self::fail('Unable to fork replay process B.');
        }
        if ($pidB === 0) {
            fclose($parentA);
            fclose($parentB);
            fclose($childA);
            $this->runReplay($sourceId, $childB);
        }

        fclose($childA);
        fclose($childB);
        try {
            self::assertSame("ready\n", fgets($parentA));
            self::assertSame("ready\n", fgets($parentB));
            fwrite($parentA, "go\n");
            fwrite($parentB, "go\n");
            $resultA = trim((string) fgets($parentA));
            $resultB = trim((string) fgets($parentB));

            pcntl_waitpid($pidA, $statusA);
            pcntl_waitpid($pidB, $statusB);
            self::assertSame(0, pcntl_wexitstatus($statusA));
            self::assertSame(0, pcntl_wexitstatus($statusB));
            self::assertStringStartsWith('replay:', $resultA);
            self::assertStringStartsWith('replay:', $resultB);

            $replayA = substr($resultA, strlen('replay:'));
            $replayB = substr($resultB, strlen('replay:'));
            self::assertSame($replayA, $replayB);

            $this->reconnectAfterFork();
            self::assertSame(1, DB::table('deliveries')->whereNotNull('replay_of_delivery_id')->count());
            self::assertSame(1, DB::table('delivery_outbox_messages')
                ->where('dedupe_key', "delivery:{$replayA}:attempt:1")
                ->count());
        } finally {
            $this->removeDeliveryFixtures();
            fclose($parentA);
            fclose($parentB);
        }
    }

    /** @return never-return */
    private function runReplay(string $sourceId, mixed $socket): void
    {
        try {
            $this->reconnectAfterFork();
            fwrite($socket, "ready\n");
            if (fgets($socket) !== "go\n") {
                throw new \LogicException('The replay concurrency barrier was not released.');
            }
            $result = app(ReplayFailedDelivery::class)->handle($sourceId, 'concurrent-replay-key');
            fwrite($socket, 'replay:'.$result->delivery->id()->toString()."\n");
            fclose($socket);
            exit(0);
        } catch (\Throwable $exception) {
            fwrite($socket, 'error:'.$exception::class."\n");
            fclose($socket);
            exit(1);
        }
    }

    private function failedSource(): string
    {
        $endpointId = (string) $this->postJson('/api/endpoints', [
            'name' => 'Concurrent replay endpoint', 'url' => 'https://receiver.example/replay',
        ])->assertCreated()->json('data.id');
        $eventId = (string) $this->postJson('/api/events', [
            'type' => 'order.paid', 'payload' => (object) [],
        ])->assertCreated()->json('data.id');
        $deliveryId = app(CreateDelivery::class)->handle($eventId, $endpointId)->id;
        DB::table('deliveries')->where('public_id', $deliveryId)->update(['status' => 'failed']);

        return $deliveryId;
    }

    private function reconnectAfterFork(): void
    {
        DB::disconnect();
        DB::purge();
        DB::connection()->getPdo();
    }

    private function removeDeliveryFixtures(): void
    {
        $this->reconnectAfterFork();
        DB::table('delivery_outbox_messages')->delete();
        DB::table('delivery_attempts')->delete();
        DB::table('deliveries')->whereNotNull('replay_of_delivery_id')->delete();
        DB::table('deliveries')->delete();
    }
}

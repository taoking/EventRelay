<?php

declare(strict_types=1);

namespace Tests\Feature\Outbox;

use App\Application\Delivery\DeliveryOutboxPublisherRepository;
use App\Application\Delivery\DeliveryTransport;
use App\Application\Delivery\EnqueuePendingDeliveries;
use App\Application\Delivery\PublishDeliveryOutbox;
use App\Domain\Delivery\DeliveryId;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DeliveryOutboxPublisherConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_independent_publishers_claim_and_publish_disjoint_messages_from_the_same_bounded_batch(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('This regression test requires MySQL/InnoDB and pcntl.');
        }

        $deliveryIds = $this->createEventWithMatchingEndpoints(2);
        $callsFile = tempnam(sys_get_temp_dir(), 'eventrelay-outbox-publisher-calls-');
        self::assertNotFalse($callsFile);
        $firstPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $secondPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($firstPair);
        self::assertNotFalse($secondPair);
        [$firstParent, $firstChild] = $firstPair;
        [$secondParent, $secondChild] = $secondPair;

        $firstPid = pcntl_fork();
        if ($firstPid === -1) {
            self::fail('Unable to fork the first outbox publisher.');
        }
        if ($firstPid === 0) {
            fclose($firstParent);
            $this->publishInChild($firstChild, $callsFile);
        }

        $secondPid = pcntl_fork();
        if ($secondPid === -1) {
            self::fail('Unable to fork the second outbox publisher.');
        }
        if ($secondPid === 0) {
            fclose($secondParent);
            $this->publishInChild($secondChild, $callsFile);
        }

        fclose($firstChild);
        fclose($secondChild);
        stream_set_timeout($firstParent, 10);
        stream_set_timeout($secondParent, 10);

        try {
            fwrite($firstParent, "start\n");
            fwrite($secondParent, "start\n");
            self::assertSame("published:1\n", fgets($firstParent));
            self::assertSame("published:1\n", fgets($secondParent));
            pcntl_waitpid($firstPid, $firstStatus);
            pcntl_waitpid($secondPid, $secondStatus);
            self::assertSame(0, pcntl_wexitstatus($firstStatus));
            self::assertSame(0, pcntl_wexitstatus($secondStatus));

            $this->reconnectAfterFork();
            $published = file($callsFile, FILE_IGNORE_NEW_LINES);
            sort($published);
            $expected = $deliveryIds;
            sort($expected);
            self::assertSame($expected, $published);
            $this->assertDatabaseCount('delivery_outbox_messages', 2);
            self::assertSame(2, DB::table('delivery_outbox_messages')->where('status', 'published')->count());
            self::assertSame(2, DB::table('delivery_outbox_messages')->where('publication_attempts', 1)->count());
        } finally {
            fclose($firstParent);
            fclose($secondParent);
            unlink($callsFile);
        }
    }

    public function test_claim_uses_stable_internal_order_honors_limit_and_records_a_lease_token(): void
    {
        $deliveryIds = $this->createEventWithMatchingEndpoints(3);
        $now = new DateTimeImmutable('2026-09-02T12:00:00+00:00');
        $claimed = app(DeliveryOutboxPublisherRepository::class)->claim(2, $now, $now->add(new DateInterval('PT60S')));

        self::assertCount(2, $claimed);
        self::assertSame(array_slice($deliveryIds, 0, 2), array_map(
            static fn ($message): string => $message->intent->deliveryId->toString(),
            $claimed,
        ));
        self::assertNotSame($claimed[0]->claimToken, $claimed[1]->claimToken);
        self::assertSame(2, DB::table('delivery_outbox_messages')->where('status', 'publishing')->count());
        self::assertSame(1, DB::table('delivery_outbox_messages')->where('status', 'pending')->count());
        self::assertSame(2, DB::table('delivery_outbox_messages')->whereNotNull('claimed_until')->count());
    }

    public function test_two_independent_recoverers_rearm_one_published_initial_intent_without_creating_a_second_row(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('This regression test requires MySQL/InnoDB and pcntl.');
        }

        $deliveryId = $this->createEventWithMatchingEndpoints(1)[0];
        $this->app->instance(DeliveryTransport::class, new class implements DeliveryTransport
        {
            public function publish(DeliveryId $deliveryId): void {}
        });
        self::assertSame(1, app(PublishDeliveryOutbox::class)->handle(1)->published);

        $firstPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $secondPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($firstPair);
        self::assertNotFalse($secondPair);
        [$firstParent, $firstChild] = $firstPair;
        [$secondParent, $secondChild] = $secondPair;

        $firstPid = pcntl_fork();
        if ($firstPid === -1) {
            self::fail('Unable to fork the first Outbox recoverer.');
        }
        if ($firstPid === 0) {
            fclose($firstParent);
            $this->recoverInChild($firstChild);
        }

        $secondPid = pcntl_fork();
        if ($secondPid === -1) {
            self::fail('Unable to fork the second Outbox recoverer.');
        }
        if ($secondPid === 0) {
            fclose($secondParent);
            $this->recoverInChild($secondChild);
        }

        fclose($firstChild);
        fclose($secondChild);
        stream_set_timeout($firstParent, 10);
        stream_set_timeout($secondParent, 10);

        try {
            fwrite($firstParent, "start\n");
            fwrite($secondParent, "start\n");
            self::assertSame("ensured:1\n", fgets($firstParent));
            self::assertSame("ensured:1\n", fgets($secondParent));
            pcntl_waitpid($firstPid, $firstStatus);
            pcntl_waitpid($secondPid, $secondStatus);
            self::assertSame(0, pcntl_wexitstatus($firstStatus));
            self::assertSame(0, pcntl_wexitstatus($secondStatus));

            $this->reconnectAfterFork();
            self::assertSame(1, DB::table('delivery_outbox_messages')->where('dedupe_key', "delivery:{$deliveryId}:attempt:1")->count());
            $this->assertDatabaseHas('delivery_outbox_messages', [
                'dedupe_key' => "delivery:{$deliveryId}:attempt:1",
                'status' => 'pending',
                'last_error_code' => 'broker_job_lost',
            ]);
        } finally {
            fclose($firstParent);
            fclose($secondParent);
        }
    }

    /** @return never-return */
    private function publishInChild(mixed $socket, string $callsFile): void
    {
        try {
            $this->reconnectAfterFork();
            app()->instance(DeliveryTransport::class, new class($callsFile) implements DeliveryTransport
            {
                public function __construct(private readonly string $callsFile) {}

                public function publish(DeliveryId $deliveryId): void
                {
                    file_put_contents($this->callsFile, $deliveryId->toString()."\n", FILE_APPEND | LOCK_EX);
                }
            });
            if (fgets($socket) !== "start\n") {
                throw new \LogicException('Publisher did not receive the test barrier release.');
            }

            $result = app(PublishDeliveryOutbox::class)->handle(1);
            fwrite($socket, "published:{$result->published}\n");
            fclose($socket);
            exit(0);
        } catch (\Throwable $exception) {
            fwrite($socket, 'error:'.get_class($exception)."\n");
            fclose($socket);
            exit(1);
        }
    }

    /** @return never-return */
    private function recoverInChild(mixed $socket): void
    {
        try {
            $this->reconnectAfterFork();
            if (fgets($socket) !== "start\n") {
                throw new \LogicException('Outbox recoverer did not receive the test barrier release.');
            }

            $result = app(EnqueuePendingDeliveries::class)->handle(1);
            fwrite($socket, "ensured:{$result->ensured}\n");
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

    /** @return list<string> */
    private function createEventWithMatchingEndpoints(int $count): array
    {
        for ($index = 1; $index <= $count; $index++) {
            $endpointId = (string) $this->postJson('/api/endpoints', [
                'name' => "Outbox publisher endpoint {$index}",
                'url' => "https://receiver.example/outbox-publisher-{$index}",
            ])->assertCreated()->json('data.id');
            $this->putJson("/api/endpoints/{$endpointId}/subscriptions", ['types' => ['order.paid']])->assertOk();
        }

        $eventId = (string) $this->postJson('/api/events', [
            'type' => 'order.paid',
            'payload' => (object) [],
        ])->assertCreated()->json('data.id');

        return DB::table('deliveries')
            ->join('events', 'deliveries.event_id', '=', 'events.id')
            ->where('events.public_id', $eventId)
            ->orderBy('deliveries.id')
            ->pluck('deliveries.public_id')
            ->all();
    }
}

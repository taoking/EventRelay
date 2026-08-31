<?php

declare(strict_types=1);

namespace Tests\Feature\Event;

use App\Application\Event\CreateEvent;
use App\Application\Event\EventIngressIdempotencyConflict;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use stdClass;
use Tests\TestCase;

final class EventIngressIdempotencyConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_mysql_processes_with_the_same_key_and_request_create_one_event_graph(): void
    {
        [$resultA, $resultB] = $this->race('event-ingress-concurrent-same', (object) ['request' => 'same'], (object) ['request' => 'same']);

        self::assertStringStartsWith('event:', $resultA);
        self::assertStringStartsWith('event:', $resultB);
        $eventA = explode(':', $resultA)[1];
        $eventB = explode(':', $resultB)[1];
        self::assertSame($eventA, $eventB);
        $this->assertGraph($eventA);
    }

    public function test_two_mysql_processes_with_the_same_key_and_different_request_create_one_winner_and_one_conflict(): void
    {
        [$resultA, $resultB] = $this->race('event-ingress-concurrent-different', (object) ['request' => 'first'], (object) ['request' => 'second']);

        $results = [$resultA, $resultB];
        $events = array_values(array_filter($results, static fn (string $result): bool => str_starts_with($result, 'event:')));
        self::assertCount(1, $events);
        self::assertSame(1, count(array_filter($results, static fn (string $result): bool => $result === 'conflict')));
        $this->assertGraph(explode(':', $events[0])[1]);
    }

    /** @return array{string, string} */
    private function race(string $key, stdClass $payloadA, stdClass $payloadB): array
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('This regression test requires MySQL/InnoDB and pcntl.');
        }

        $endpointId = (string) $this->postJson('/api/endpoints', [
            'name' => 'Event ingress concurrency endpoint',
            'url' => 'https://receiver.example/event-ingress-concurrency',
        ])->assertCreated()->json('data.id');
        $this->putJson("/api/endpoints/{$endpointId}/subscriptions", ['types' => ['order.paid']])->assertOk();

        [$parentA, $childA] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        [$parentB, $childB] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($parentA);
        self::assertNotFalse($childA);
        self::assertNotFalse($parentB);
        self::assertNotFalse($childB);

        $pidA = pcntl_fork();
        if ($pidA === -1) {
            self::fail('Unable to fork event ingress process A.');
        }
        if ($pidA === 0) {
            fclose($parentA);
            fclose($parentB);
            fclose($childB);
            $this->createInChild($childA, $key, $payloadA);
        }

        $pidB = pcntl_fork();
        if ($pidB === -1) {
            self::fail('Unable to fork event ingress process B.');
        }
        if ($pidB === 0) {
            fclose($parentA);
            fclose($parentB);
            fclose($childA);
            $this->createInChild($childB, $key, $payloadB);
        }

        fclose($childA);
        fclose($childB);
        stream_set_timeout($parentA, 10);
        stream_set_timeout($parentB, 10);

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

            $this->reconnectAfterFork();

            return [$resultA, $resultB];
        } finally {
            fclose($parentA);
            fclose($parentB);
        }
    }

    /** @return never-return */
    private function createInChild(mixed $socket, string $key, stdClass $payload): void
    {
        try {
            $this->reconnectAfterFork();
            fwrite($socket, "ready\n");
            if (fgets($socket) !== "go\n") {
                throw new \LogicException('The event ingress concurrency barrier was not released.');
            }

            $result = app(CreateEvent::class)->handleWithIdempotency('order.paid', $payload, $key);
            fwrite($socket, 'event:'.$result->event->id.':'.($result->created ? 'created' : 'existing')."\n");
            fclose($socket);
            exit(0);
        } catch (EventIngressIdempotencyConflict) {
            fwrite($socket, "conflict\n");
            fclose($socket);
            exit(0);
        } catch (\Throwable $exception) {
            fwrite($socket, 'error:'.$exception::class."\n");
            fclose($socket);
            exit(1);
        }
    }

    private function assertGraph(string $eventId): void
    {
        self::assertSame(1, DB::table('events')->count());
        self::assertSame(1, DB::table('event_ingress_idempotencies')->count());
        self::assertSame(1, DB::table('deliveries')->join('events', 'deliveries.event_id', '=', 'events.id')->where('events.public_id', $eventId)->count());
        self::assertSame(1, DB::table('delivery_outbox_messages')->count());
    }

    private function reconnectAfterFork(): void
    {
        DB::disconnect();
        DB::purge();
        DB::connection()->getPdo();
    }
}

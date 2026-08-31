<?php

declare(strict_types=1);

namespace Tests\Feature\DeadLetter;

use App\Application\Clock\Clock;
use App\Application\DeadLetter\DeadLetterFilter;
use App\Application\DeadLetter\ListDeadLetters;
use App\Application\Delivery\CreateDelivery;
use App\Application\Delivery\ProcessPendingDelivery;
use App\Application\Delivery\WebhookRequest;
use App\Application\Delivery\WebhookResponse;
use App\Application\Delivery\WebhookTarget;
use App\Application\Delivery\WebhookTargetResolver;
use App\Application\Delivery\WebhookTransport;
use App\Domain\Delivery\DeliveryId;
use DateTimeImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Support\FrozenClock;
use Tests\TestCase;

final class DeadLetterCommitVisibilityConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_a_reader_sees_neither_a_torn_final_failure_nor_incomplete_latest_attempt_metadata(): void
    {
        $this->requireMySqlAndPcntl();
        $deliveryId = $this->createPendingDelivery();
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$parent, $child] = $pair;
        $pid = pcntl_fork();
        if ($pid === -1) {
            self::fail('Unable to fork the delivery worker.');
        }
        if ($pid === 0) {
            fclose($parent);
            $this->finalizeInChild($deliveryId, $child);
        }

        fclose($child);
        stream_set_timeout($parent, 10);

        try {
            self::assertSame("attempt-updated-uncommitted\n", fgets($parent));
            $beforeCommit = app(ListDeadLetters::class)->handle(new DeadLetterFilter(null, null, null, null, 50), null);
            self::assertSame([], $beforeCommit->items);

            fwrite($parent, "release\n");
            self::assertSame("finalized\n", fgets($parent));
            pcntl_waitpid($pid, $status);
            self::assertSame(0, pcntl_wexitstatus($status));

            $this->reconnectAfterFork();
            $afterCommit = app(ListDeadLetters::class)->handle(new DeadLetterFilter(null, null, null, null, 50), null);
            self::assertCount(1, $afterCommit->items);
            self::assertSame($deliveryId, $afterCommit->items[0]->deliveryId);
            self::assertSame(1, $afterCommit->items[0]->lastAttemptNumber);
            self::assertSame('http_status', $afterCommit->items[0]->failureType);
            self::assertSame(400, $afterCommit->items[0]->responseStatus);
            self::assertDatabaseHas('deliveries', ['public_id' => $deliveryId, 'status' => 'failed']);
            $this->assertDatabaseHas('delivery_attempts', ['attempt_number' => 1, 'status' => 'failed', 'finished_at' => '2026-09-06 14:00:00']);
        } finally {
            fclose($parent);
        }
    }

    /** @return never-return */
    private function finalizeInChild(string $deliveryId, mixed $socket): void
    {
        try {
            $this->reconnectAfterFork();
            app()->instance(Clock::class, new FrozenClock(new DateTimeImmutable('2026-09-06T14:00:00+00:00')));
            app()->instance(WebhookTargetResolver::class, new class implements WebhookTargetResolver
            {
                public function resolve(string $url): WebhookTarget
                {
                    return new WebhookTarget($url, 'receiver.example', 443, '1.1.1.1');
                }
            });
            app()->instance(WebhookTransport::class, new class implements WebhookTransport
            {
                public function send(WebhookTarget $target, WebhookRequest $request): WebhookResponse
                {
                    return new WebhookResponse(400, 1);
                }
            });
            DB::listen(function (QueryExecuted $query) use ($socket): void {
                if (! str_contains(strtolower($query->sql), 'update `delivery_attempts`')) {
                    return;
                }

                fwrite($socket, "attempt-updated-uncommitted\n");
                if (fgets($socket) !== "release\n") {
                    throw new \LogicException('The worker did not receive the transaction barrier release.');
                }
            });

            app(ProcessPendingDelivery::class)->handle(DeliveryId::fromString($deliveryId));
            fwrite($socket, "finalized\n");
            fclose($socket);
            exit(0);
        } catch (\Throwable $exception) {
            fwrite($socket, 'error:'.get_class($exception)."\n");
            fclose($socket);
            exit(1);
        }
    }

    private function createPendingDelivery(): string
    {
        $endpointId = (string) $this->postJson('/api/endpoints', [
            'name' => 'DLQ commit visibility endpoint',
            'url' => 'https://receiver.example/dlq-commit-visibility',
        ])->assertCreated()->json('data.id');
        $eventId = (string) $this->postJson('/api/events', [
            'type' => 'order.paid',
            'payload' => (object) [],
        ])->assertCreated()->json('data.id');

        return app(CreateDelivery::class)->handle($eventId, $endpointId)->id;
    }

    private function requireMySqlAndPcntl(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('This regression test requires MySQL/InnoDB and pcntl.');
        }
    }

    private function reconnectAfterFork(): void
    {
        DB::disconnect();
        DB::purge();
        DB::connection()->getPdo();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Application\Delivery\CreateDelivery;
use App\Application\Delivery\ProcessPendingDelivery;
use App\Application\Delivery\WebhookRequest;
use App\Application\Delivery\WebhookResponse;
use App\Application\Delivery\WebhookTarget;
use App\Application\Delivery\WebhookTargetResolver;
use App\Application\Delivery\WebhookTransport;
use App\Domain\Delivery\DeliveryId;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DueRetryConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_independent_workers_claim_one_due_retry_and_create_only_attempt_two(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('This regression test requires MySQL/InnoDB and pcntl.');
        }

        $deliveryId = $this->createDueRetry();
        $callsFile = tempnam(sys_get_temp_dir(), 'eventrelay-due-retry-calls-');
        self::assertNotFalse($callsFile);
        $winnerPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($winnerPair);
        [$parent, $child] = $winnerPair;
        $winnerPid = pcntl_fork();

        if ($winnerPid === -1) {
            self::fail('Unable to fork the due retry winner.');
        }

        if ($winnerPid === 0) {
            fclose($parent);
            $this->runWinner($deliveryId, $child, $callsFile);
        }

        fclose($child);
        stream_set_timeout($parent, 10);

        try {
            self::assertSame("entered-transport\n", fgets($parent));
            $loserPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            self::assertNotFalse($loserPair);
            [$loserParent, $loserChild] = $loserPair;
            $loserPid = pcntl_fork();

            if ($loserPid === -1) {
                self::fail('Unable to fork the due retry loser.');
            }

            if ($loserPid === 0) {
                fclose($loserParent);
                $this->runLoser($deliveryId, $loserChild, $callsFile);
            }

            fclose($loserChild);
            stream_set_timeout($loserParent, 10);
            self::assertSame("success\n", fgets($loserParent));
            pcntl_waitpid($loserPid, $loserStatus);
            self::assertSame(0, pcntl_wexitstatus($loserStatus));
            fclose($loserParent);

            fwrite($parent, "release\n");
            self::assertSame("success\n", fgets($parent));
            pcntl_waitpid($winnerPid, $winnerStatus);
            self::assertSame(0, pcntl_wexitstatus($winnerStatus));

            $this->reconnectAfterFork();
            self::assertSame(['called'], file($callsFile, FILE_IGNORE_NEW_LINES));
            $this->assertDatabaseHas('deliveries', ['public_id' => $deliveryId, 'status' => 'succeeded', 'next_attempt_at' => null]);
            $this->assertDatabaseHas('delivery_attempts', ['attempt_number' => 1, 'status' => 'failed']);
            $this->assertDatabaseHas('delivery_attempts', ['attempt_number' => 2, 'status' => 'succeeded']);
            self::assertSame(2, DB::table('delivery_attempts')->count());
        } finally {
            fclose($parent);
            unlink($callsFile);
        }
    }

    /** @return never-return */
    private function runWinner(string $deliveryId, mixed $socket, string $callsFile): void
    {
        try {
            $this->reconnectAfterFork();
            $this->bindResolver();
            app()->instance(WebhookTransport::class, new class($socket, $callsFile) implements WebhookTransport
            {
                public function __construct(private readonly mixed $socket, private readonly string $callsFile) {}

                public function send(WebhookTarget $target, WebhookRequest $request): WebhookResponse
                {
                    file_put_contents($this->callsFile, "called\n", FILE_APPEND | LOCK_EX);
                    fwrite($this->socket, "entered-transport\n");

                    if (fgets($this->socket) !== "release\n") {
                        throw new \LogicException('Winner did not receive the test barrier release.');
                    }

                    return new WebhookResponse(200, 1);
                }
            });
            app(ProcessPendingDelivery::class)->handle(DeliveryId::fromString($deliveryId));
            fwrite($socket, "success\n");
            fclose($socket);
            exit(0);
        } catch (\Throwable $exception) {
            fwrite($socket, 'error:'.get_class($exception)."\n");
            fclose($socket);
            exit(1);
        }
    }

    /** @return never-return */
    private function runLoser(string $deliveryId, mixed $socket, string $callsFile): void
    {
        try {
            $this->reconnectAfterFork();
            $this->bindResolver();
            app()->instance(WebhookTransport::class, new class($callsFile) implements WebhookTransport
            {
                public function __construct(private readonly string $callsFile) {}

                public function send(WebhookTarget $target, WebhookRequest $request): WebhookResponse
                {
                    file_put_contents($this->callsFile, "called\n", FILE_APPEND | LOCK_EX);

                    return new WebhookResponse(200, 1);
                }
            });
            app(ProcessPendingDelivery::class)->handle(DeliveryId::fromString($deliveryId));
            fwrite($socket, "success\n");
            fclose($socket);
            exit(0);
        } catch (\Throwable $exception) {
            fwrite($socket, 'error:'.get_class($exception)."\n");
            fclose($socket);
            exit(1);
        }
    }

    private function bindResolver(): void
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

    private function createDueRetry(): string
    {
        $endpointId = (string) $this->postJson('/api/endpoints', [
            'name' => 'Due retry concurrency receiver',
            'url' => 'https://receiver.example/due-retry',
        ])->assertCreated()->json('data.id');
        $eventId = (string) $this->postJson('/api/events', [
            'type' => 'order.paid',
            'payload' => (object) [],
        ])->assertCreated()->json('data.id');
        $deliveryId = app(CreateDelivery::class)->handle($eventId, $endpointId)->id;
        $internalId = DB::table('deliveries')->where('public_id', $deliveryId)->value('id');
        self::assertIsInt($internalId);

        DB::table('deliveries')->where('id', $internalId)->update([
            'status' => 'retry_scheduled',
            'next_attempt_at' => now()->subSecond(),
        ]);
        DB::table('delivery_attempts')->insert([
            'public_id' => '94b4d301-f44a-4dab-a545-6f9046cbeb6f',
            'delivery_id' => $internalId,
            'attempt_number' => 1,
            'status' => 'failed',
            'response_status' => 500,
            'failure_type' => 'http_status',
            'failure_message' => 'HTTP 500',
            'duration_ms' => 1,
            'started_at' => now()->subSeconds(2),
            'finished_at' => now()->subSecond(),
            'created_at' => now()->subSeconds(2),
            'updated_at' => now()->subSecond(),
        ]);

        return $deliveryId;
    }
}

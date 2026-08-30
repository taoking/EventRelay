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

final class ProcessPendingDeliveryConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_independent_processes_execute_the_real_pending_delivery_path_with_one_http_call_and_one_attempt(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('This regression test requires MySQL/InnoDB and pcntl.');
        }

        $deliveryId = $this->createPendingDelivery();
        $callsFile = tempnam(sys_get_temp_dir(), 'eventrelay-webhook-calls-');
        self::assertNotFalse($callsFile);
        $winnerPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($winnerPair);
        [$winnerParent, $winnerChild] = $winnerPair;
        $winnerPid = pcntl_fork();

        if ($winnerPid === -1) {
            self::fail('Unable to fork the first delivery processor.');
        }

        if ($winnerPid === 0) {
            fclose($winnerParent);
            $this->runWinner($deliveryId, $winnerChild, $callsFile);
        }

        fclose($winnerChild);
        stream_set_timeout($winnerParent, 10);

        try {
            self::assertSame("entered-transport\n", fgets($winnerParent));

            $loserPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            self::assertNotFalse($loserPair);
            [$loserParent, $loserChild] = $loserPair;
            $loserPid = pcntl_fork();

            if ($loserPid === -1) {
                self::fail('Unable to fork the second delivery processor.');
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

            fwrite($winnerParent, "release\n");
            self::assertSame("success\n", fgets($winnerParent));
            pcntl_waitpid($winnerPid, $winnerStatus);
            self::assertSame(0, pcntl_wexitstatus($winnerStatus));

            $this->reconnectAfterFork();
            self::assertSame(['called'], file($callsFile, FILE_IGNORE_NEW_LINES));
            $this->assertDatabaseHas('deliveries', ['public_id' => $deliveryId, 'status' => 'succeeded']);
            self::assertSame(1, DB::table('delivery_attempts')->where('attempt_number', 1)->count());
            $this->assertDatabaseHas('delivery_attempts', ['attempt_number' => 1, 'status' => 'succeeded']);
        } finally {
            fclose($winnerParent);
            unlink($callsFile);
        }
    }

    /** @return never-return */
    private function runWinner(string $deliveryId, mixed $socket, string $callsFile): void
    {
        try {
            $this->reconnectAfterFork();
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
            $this->bindTargetResolver();
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
            'name' => 'Concurrent delivery processor receiver',
            'url' => 'https://receiver.example/concurrency',
        ])->assertCreated()->json('data.id');
        $eventId = (string) $this->postJson('/api/events', [
            'type' => 'order.paid',
            'payload' => (object) [],
        ])->assertCreated()->json('data.id');

        return app(CreateDelivery::class)->handle($eventId, $endpointId)->id;
    }
}

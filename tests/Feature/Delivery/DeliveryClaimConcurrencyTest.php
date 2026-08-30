<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Application\Delivery\CreateDelivery;
use App\Application\Delivery\DeliveryExecutionRepository;
use App\Domain\Delivery\DeliveryId;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DeliveryClaimConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_independent_mysql_processes_racing_to_claim_one_delivery_create_only_one_attempt(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('This regression test requires MySQL/InnoDB and pcntl.');
        }

        $deliveryId = $this->createPendingDelivery();
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $pid = pcntl_fork();

        if ($pid === -1) {
            self::fail('Unable to fork a second claimant process.');
        }

        if ($pid === 0) {
            fclose($parent);
            DB::disconnect();
            DB::purge();
            fwrite($child, "ready\n");
            fgets($child);

            $claimed = app(DeliveryExecutionRepository::class)->claim(DeliveryId::fromString($deliveryId));
            fwrite($child, $claimed === null ? "noop\n" : "claimed\n");
            fclose($child);
            exit(0);
        }

        fclose($child);

        try {
            self::assertSame("ready\n", fgets($parent));
            fwrite($parent, "go\n");
            $parentClaim = app(DeliveryExecutionRepository::class)->claim(DeliveryId::fromString($deliveryId));
            $childResult = fgets($parent);
            pcntl_waitpid($pid, $status);

            self::assertSame(0, pcntl_wexitstatus($status));
            self::assertSame(1, (int) ($parentClaim !== null) + (int) ($childResult === "claimed\n"));
            $this->assertDatabaseHas('deliveries', ['public_id' => $deliveryId, 'status' => 'processing']);
            self::assertSame(1, DB::table('delivery_attempts')->count());
            $this->assertDatabaseHas('delivery_attempts', ['attempt_number' => 1, 'status' => 'started']);
        } finally {
            fclose($parent);
        }
    }

    private function createPendingDelivery(): string
    {
        $endpointId = (string) $this->postJson('/api/endpoints', [
            'name' => 'Concurrent claimant receiver',
            'url' => 'https://receiver.example/concurrency',
        ])->assertCreated()->json('data.id');
        $eventId = (string) $this->postJson('/api/events', [
            'type' => 'order.paid',
            'payload' => (object) [],
        ])->assertCreated()->json('data.id');

        return app(CreateDelivery::class)->handle($eventId, $endpointId)->id;
    }
}

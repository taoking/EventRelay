<?php

declare(strict_types=1);

namespace Tests\Feature\Endpoint;

use App\Application\Delivery\CreateDelivery;
use App\Application\Delivery\ReplayFailedDelivery;
use App\Application\Endpoint\UpdateEndpoint;
use App\Application\EndpointSigningSecret\RotateEndpointSigningSecret;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DeliveryReplayEndpointSnapshotConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_replay_started_against_a_locked_endpoint_snapshots_one_committed_url_and_signing_key_version(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('This regression test requires MySQL/InnoDB and pcntl.');
        }

        [$endpointId, $sourceId] = $this->failedSignedSource();
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($parent);
        self::assertNotFalse($child);
        $pid = pcntl_fork();
        if ($pid === -1) {
            self::fail('Unable to fork the replay snapshot process.');
        }
        if ($pid === 0) {
            fclose($parent);
            $this->runReplay($sourceId, $child);
        }

        fclose($child);
        try {
            self::assertSame("ready\n", fgets($parent));
            DB::beginTransaction();
            DB::table('endpoints')->where('public_id', $endpointId)->lock('for update')->first();
            $keyTwo = app(RotateEndpointSigningSecret::class)->handle($endpointId);
            app(UpdateEndpoint::class)->handle($endpointId, null, 'https://receiver.example/config-two', null);
            fwrite($parent, "replay\n");
            DB::commit();

            $result = trim((string) fgets($parent));
            pcntl_waitpid($pid, $status);
            self::assertSame(0, pcntl_wexitstatus($status));
            self::assertStringStartsWith('replay:', $result);
            $replayId = substr($result, strlen('replay:'));

            $this->reconnectAfterFork();
            $replay = DB::table('deliveries')->where('public_id', $replayId)->first();
            self::assertNotNull($replay);
            self::assertSame('https://receiver.example/config-two', $replay->target_url);
            self::assertSame($keyTwo->keyId, DB::table('endpoint_signing_secrets')->where('id', $replay->signing_secret_id)->value('public_id'));
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->removeDeliveryFixtures();
            fclose($parent);
        }
    }

    /** @return never-return */
    private function runReplay(string $sourceId, mixed $socket): void
    {
        try {
            $this->reconnectAfterFork();
            fwrite($socket, "ready\n");
            if (fgets($socket) !== "replay\n") {
                throw new \LogicException('The endpoint snapshot barrier was not released.');
            }
            $result = app(ReplayFailedDelivery::class)->handle($sourceId, 'endpoint-snapshot-replay');
            fwrite($socket, 'replay:'.$result->delivery->id()->toString()."\n");
            fclose($socket);
            exit(0);
        } catch (\Throwable $exception) {
            fwrite($socket, 'error:'.$exception::class."\n");
            fclose($socket);
            exit(1);
        }
    }

    /** @return array{string,string} */
    private function failedSignedSource(): array
    {
        $endpointId = (string) $this->postJson('/api/endpoints', [
            'name' => 'Replay snapshot endpoint', 'url' => 'https://receiver.example/config-one',
        ])->assertCreated()->json('data.id');
        app(RotateEndpointSigningSecret::class)->handle($endpointId);
        $eventId = (string) $this->postJson('/api/events', [
            'type' => 'order.paid', 'payload' => (object) [],
        ])->assertCreated()->json('data.id');
        $sourceId = app(CreateDelivery::class)->handle($eventId, $endpointId)->id;
        DB::table('deliveries')->where('public_id', $sourceId)->update(['status' => 'failed']);

        return [$endpointId, $sourceId];
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

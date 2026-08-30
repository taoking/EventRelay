<?php

declare(strict_types=1);

namespace Tests\Feature\Endpoint;

use App\Application\Delivery\CreateDelivery;
use App\Application\EndpointSigningSecret\RotateEndpointSigningSecret;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DeliverySigningSnapshotRotationConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_delivery_created_after_a_locked_rotation_snapshots_the_rotated_key(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('This regression test requires MySQL/InnoDB and pcntl.');
        }

        $endpointId = (string) $this->postJson('/api/endpoints', [
            'name' => 'Snapshot/rotation receiver', 'url' => 'https://receiver.example/snapshot',
        ])->assertCreated()->json('data.id');
        app(RotateEndpointSigningSecret::class)->handle($endpointId);
        $eventId = (string) $this->postJson('/api/events', [
            'type' => 'order.paid', 'payload' => (object) [],
        ])->assertCreated()->json('data.id');
        [$rotationParent, $rotationChild] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($rotationParent);
        self::assertNotFalse($rotationChild);
        $rotationPid = pcntl_fork();

        if ($rotationPid === -1) {
            self::fail('Unable to fork the rotation process.');
        }
        if ($rotationPid === 0) {
            fclose($rotationParent);
            $this->runLockedRotation($endpointId, $rotationChild);
        }

        fclose($rotationChild);
        try {
            $message = fgets($rotationParent);
            self::assertIsString($message);
            self::assertStringStartsWith('rotation-written:', $message);
            $keyTwoId = trim(substr($message, strlen('rotation-written:')));

            [$deliveryParent, $deliveryChild] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            self::assertNotFalse($deliveryParent);
            self::assertNotFalse($deliveryChild);
            $deliveryPid = pcntl_fork();
            if ($deliveryPid === -1) {
                self::fail('Unable to fork the delivery snapshot process.');
            }
            if ($deliveryPid === 0) {
                fclose($deliveryParent);
                $this->runDeliveryCreation($eventId, $endpointId, $deliveryChild);
            }

            fclose($deliveryChild);
            self::assertSame("about-to-create\n", fgets($deliveryParent));
            fwrite($rotationParent, "commit\n");
            self::assertSame("rotation-committed\n", fgets($rotationParent));
            self::assertStringStartsWith('delivery:', (string) fgets($deliveryParent));
            pcntl_waitpid($rotationPid, $rotationStatus);
            pcntl_waitpid($deliveryPid, $deliveryStatus);
            self::assertSame(0, pcntl_wexitstatus($rotationStatus));
            self::assertSame(0, pcntl_wexitstatus($deliveryStatus));

            $this->reconnectAfterFork();
            self::assertSame($keyTwoId, DB::table('deliveries')
                ->join('endpoint_signing_secrets', 'deliveries.signing_secret_id', '=', 'endpoint_signing_secrets.id')
                ->where('deliveries.event_id', DB::table('events')->where('public_id', $eventId)->value('id'))
                ->value('endpoint_signing_secrets.public_id'));
        } finally {
            fclose($rotationParent);
        }
    }

    /** @return never-return */
    private function runLockedRotation(string $endpointId, mixed $socket): void
    {
        try {
            $this->reconnectAfterFork();
            DB::beginTransaction();
            DB::table('endpoints')->where('public_id', $endpointId)->lock('for update')->first();
            $rotated = app(RotateEndpointSigningSecret::class)->handle($endpointId);
            fwrite($socket, 'rotation-written:'.$rotated->keyId."\n");
            if (fgets($socket) !== "commit\n") {
                throw new \LogicException('The rotation test barrier was not released.');
            }
            DB::commit();
            fwrite($socket, "rotation-committed\n");
            fclose($socket);
            exit(0);
        } catch (\Throwable $exception) {
            DB::rollBack();
            fwrite($socket, 'error:'.$exception::class."\n");
            fclose($socket);
            exit(1);
        }
    }

    /** @return never-return */
    private function runDeliveryCreation(string $eventId, string $endpointId, mixed $socket): void
    {
        try {
            $this->reconnectAfterFork();
            fwrite($socket, "about-to-create\n");
            $delivery = app(CreateDelivery::class)->handle($eventId, $endpointId);
            fwrite($socket, 'delivery:'.$delivery->id."\n");
            fclose($socket);
            exit(0);
        } catch (\Throwable $exception) {
            fwrite($socket, 'error:'.$exception::class."\n");
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
}

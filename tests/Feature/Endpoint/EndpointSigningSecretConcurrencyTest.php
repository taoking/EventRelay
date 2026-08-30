<?php

declare(strict_types=1);

namespace Tests\Feature\Endpoint;

use App\Application\EndpointSigningSecret\RotateEndpointSigningSecret;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class EndpointSigningSecretConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_independent_mysql_rotations_are_serialized_by_the_endpoint_lock(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('This regression test requires MySQL/InnoDB and pcntl.');
        }

        $endpointId = (string) $this->postJson('/api/endpoints', [
            'name' => 'Concurrent signing key endpoint', 'url' => 'https://receiver.example/signing',
        ])->assertCreated()->json('data.id');
        app(RotateEndpointSigningSecret::class)->handle($endpointId);
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $pid = pcntl_fork();

        if ($pid === -1) {
            self::fail('Unable to fork a second key rotation process.');
        }

        if ($pid === 0) {
            fclose($parent);
            DB::disconnect();
            DB::purge();
            fwrite($child, "ready\n");
            fgets($child);
            $rotated = app(RotateEndpointSigningSecret::class)->handle($endpointId);
            fwrite($child, $rotated->version."\n");
            fclose($child);
            exit(0);
        }

        fclose($child);
        try {
            self::assertSame("ready\n", fgets($parent));
            fwrite($parent, "go\n");
            $parentRotation = app(RotateEndpointSigningSecret::class)->handle($endpointId);
            $childVersion = (int) fgets($parent);
            pcntl_waitpid($pid, $status);

            self::assertSame(0, pcntl_wexitstatus($status));
            $versions = [$parentRotation->version, $childVersion];
            sort($versions);
            self::assertSame([2, 3], $versions);
            self::assertSame(3, (int) DB::table('endpoint_signing_secrets')->max('version'));
            self::assertSame(1, DB::table('endpoint_signing_secrets')->whereNull('retired_at')->count());
            self::assertSame(3, DB::table('endpoint_signing_secrets')->count());
            self::assertSame(3, (int) DB::table('endpoint_signing_secrets')
                ->join('endpoints', 'endpoints.current_signing_secret_id', '=', 'endpoint_signing_secrets.id')
                ->where('endpoints.public_id', $endpointId)
                ->value('endpoint_signing_secrets.version'));
        } finally {
            fclose($parent);
        }
    }
}

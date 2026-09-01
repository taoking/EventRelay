<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Application\Operations\OperationalReadinessRepository;
use App\Infrastructure\Operations\OperationsEndpointConfiguration;
use App\Infrastructure\Persistence\Eloquent\EloquentOperationalReadModel;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

final class MySqlWritableReadinessTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'operations.enabled' => true,
            'operations.bearer_token' => 'operations-test-token',
        ]);
        $this->app->forgetInstance(OperationsEndpointConfiguration::class);
    }

    public function test_a_writable_mysql_probe_commits_without_business_state_or_persistent_probe_rows(): void
    {
        $this->requireMysql();

        $before = $this->businessStateCounts();

        for ($probe = 0; $probe < 5; $probe++) {
            self::assertTrue(app(OperationalReadinessRepository::class)->isMysqlAvailable());
        }

        self::assertSame($before, $this->businessStateCounts());
        self::assertSame(0, DB::table('operational_readiness_probes')->count());
    }

    public function test_readiness_rejects_a_mysql_connection_that_can_select_but_cannot_write_then_recovers_without_restart(): void
    {
        $this->requireMysql();

        $endpointId = (string) $this->postJson('/api/endpoints', [
            'name' => 'Writable readiness receiver',
            'url' => 'https://receiver.example/operations-readiness',
        ])->assertCreated()->json('data.id');
        $this->putJson("/api/endpoints/{$endpointId}/subscriptions", ['types' => ['order.paid']])->assertOk();

        $select = DB::selectOne('SELECT 1 AS probe_select');
        self::assertSame(1, (int) $select->probe_select);

        DB::statement('SET SESSION TRANSACTION READ ONLY');

        try {
            $this->withToken('operations-test-token')->get('/internal/health/live')
                ->assertOk()
                ->assertExactJson(['status' => 'alive']);
            $this->withToken('operations-test-token')->get('/internal/health/ready')
                ->assertStatus(503)
                ->assertExactJson([
                    'status' => 'not_ready',
                    'checks' => ['mysql' => 'down'],
                ])
                ->assertDontSee('SQLSTATE')
                ->assertDontSee('HY000')
                ->assertDontSee('READ ONLY');
        } finally {
            DB::statement('SET SESSION TRANSACTION READ WRITE');
        }

        $this->withToken('operations-test-token')->get('/internal/health/ready')
            ->assertOk()
            ->assertExactJson([
                'status' => 'ready',
                'checks' => ['mysql' => 'up'],
            ]);

        $eventId = (string) $this->postJson('/api/events', [
            'type' => 'order.paid',
            'payload' => (object) [],
        ])->assertCreated()->json('data.id');

        self::assertNotSame('', $eventId);
        self::assertSame(1, DB::table('events')->count());
        self::assertSame(1, DB::table('deliveries')->count());
        self::assertSame(1, DB::table('delivery_outbox_messages')->count());
        self::assertSame(0, DB::table('operational_readiness_probes')->count());
    }

    public function test_two_independent_mysql_processes_can_probe_concurrently_without_persistent_rows(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('This regression test requires MySQL/InnoDB and pcntl.');
        }

        $firstPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $secondPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($firstPair);
        self::assertNotFalse($secondPair);
        [$firstParent, $firstChild] = $firstPair;
        [$secondParent, $secondChild] = $secondPair;

        $firstPid = pcntl_fork();
        if ($firstPid === -1) {
            self::fail('Unable to fork the first readiness probe.');
        }
        if ($firstPid === 0) {
            fclose($firstParent);
            $this->probeInChild($firstChild);
        }

        $secondPid = pcntl_fork();
        if ($secondPid === -1) {
            self::fail('Unable to fork the second readiness probe.');
        }
        if ($secondPid === 0) {
            fclose($secondParent);
            $this->probeInChild($secondChild);
        }

        fclose($firstChild);
        fclose($secondChild);
        stream_set_timeout($firstParent, 10);
        stream_set_timeout($secondParent, 10);

        try {
            self::assertSame("ready\n", fgets($firstParent));
            self::assertSame("ready\n", fgets($secondParent));
            fwrite($firstParent, "start\n");
            fwrite($secondParent, "start\n");
            self::assertSame("available\n", fgets($firstParent));
            self::assertSame("available\n", fgets($secondParent));

            pcntl_waitpid($firstPid, $firstStatus);
            pcntl_waitpid($secondPid, $secondStatus);
            self::assertSame(0, pcntl_wexitstatus($firstStatus));
            self::assertSame(0, pcntl_wexitstatus($secondStatus));
        } finally {
            fclose($firstParent);
            fclose($secondParent);
        }

        DB::disconnect();
        DB::purge();
        self::assertSame(0, DB::table('operational_readiness_probes')->count());
    }

    public function test_unexpected_programming_failures_are_not_converted_to_not_ready(): void
    {
        $defaultConnection = DB::getDefaultConnection();

        try {
            DB::setDefaultConnection('missing-readiness-connection');

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Database connection [missing-readiness-connection] not configured.');

            app(EloquentOperationalReadModel::class)->isMysqlAvailable();
        } finally {
            DB::setDefaultConnection($defaultConnection);
        }
    }

    /** @return array<string, int> */
    private function businessStateCounts(): array
    {
        return [
            'events' => DB::table('events')->count(),
            'deliveries' => DB::table('deliveries')->count(),
            'attempts' => DB::table('delivery_attempts')->count(),
            'outbox' => DB::table('delivery_outbox_messages')->count(),
        ];
    }

    /** @return never-return */
    private function probeInChild(mixed $socket): void
    {
        try {
            DB::disconnect();
            DB::purge();
            fwrite($socket, "ready\n");

            if (fgets($socket) !== "start\n") {
                throw new LogicException('The readiness probe did not receive its barrier release.');
            }

            $available = app(EloquentOperationalReadModel::class)->isMysqlAvailable();
            fwrite($socket, $available ? "available\n" : "not-available\n");
            fclose($socket);
            exit($available ? 0 : 1);
        } catch (\Throwable) {
            fwrite($socket, "error\n");
            fclose($socket);
            exit(1);
        }
    }

    private function requireMysql(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('This regression test requires MySQL/InnoDB.');
        }
    }
}

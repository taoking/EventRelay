<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Application\Delivery\CreateDelivery;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DeliveryConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_a_locking_recovery_read_returns_the_winner_after_a_repeatable_read_snapshot(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('This regression test requires MySQL/InnoDB REPEATABLE READ.');
        }

        $eventId = $this->createEvent('order.paid');
        $endpointId = $this->createEndpoint();

        /** @var int $eventInternalId */
        $eventInternalId = DB::table('events')->where('public_id', $eventId)->value('id');
        /** @var int $endpointInternalId */
        $endpointInternalId = DB::table('endpoints')->where('public_id', $endpointId)->value('id');

        $originalConnection = DB::getDefaultConnection();
        $winnerConnection = 'delivery_concurrency_winner';
        $loserConnection = 'delivery_concurrency_loser';

        config([
            "database.connections.{$winnerConnection}" => config('database.connections.mysql'),
            "database.connections.{$loserConnection}" => config('database.connections.mysql'),
        ]);
        DB::purge($winnerConnection);
        DB::purge($loserConnection);
        DB::setDefaultConnection($loserConnection);

        $loser = DB::connection($loserConnection);
        $loser->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $loser->beginTransaction();

        try {
            $loser->table('events')->where('id', $eventInternalId)->first();

            DB::setDefaultConnection($winnerConnection);
            $winner = app(CreateDelivery::class)->handle($eventId, $endpointId);

            DB::setDefaultConnection($loserConnection);
            $recovered = app(CreateDelivery::class)->handle($eventId, $endpointId);

            self::assertSame($winner->id, $recovered->id);
            $loser->commit();
        } finally {
            if ($loser->transactionLevel() > 0) {
                $loser->rollBack();
            }

            DB::setDefaultConnection($originalConnection);
            DB::purge($winnerConnection);
            DB::purge($loserConnection);
        }

        self::assertSame(1, DB::connection($originalConnection)->table('deliveries')
            ->where('event_id', $eventInternalId)
            ->where('endpoint_id', $endpointInternalId)
            ->count());
    }

    private function createEndpoint(): string
    {
        $response = $this->postJson('/api/endpoints', [
            'name' => 'Concurrent delivery endpoint',
            'url' => 'https://example.test/webhooks/concurrent-delivery',
        ])->assertCreated();

        return (string) $response->json('data.id');
    }

    private function createEvent(string $type): string
    {
        $response = $this->postJson('/api/events', [
            'type' => $type,
            'payload' => (object) ['source' => 'delivery-concurrency-test'],
        ])->assertCreated();

        return (string) $response->json('data.id');
    }
}

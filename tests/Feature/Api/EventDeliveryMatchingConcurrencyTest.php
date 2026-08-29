<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Application\Event\CreateEvent;
use App\Application\Subscription\SubscriptionMatcher;
use App\Domain\Event\EventType;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class EventDeliveryMatchingConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_a_matched_endpoint_cannot_be_disabled_before_its_delivery_plan_is_created(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('This regression test requires MySQL/InnoDB row locks.');
        }

        $endpointId = $this->createEndpoint();
        $this->putJson("/api/endpoints/{$endpointId}/subscriptions", [
            'types' => ['order.paid'],
        ])->assertOk();

        $originalConnection = DB::getDefaultConnection();
        $matcherConnection = 'event_matching_matcher';
        $updaterConnection = 'event_matching_updater';

        config([
            "database.connections.{$matcherConnection}" => config('database.connections.mysql'),
            "database.connections.{$updaterConnection}" => config('database.connections.mysql'),
        ]);
        DB::purge($matcherConnection);
        DB::purge($updaterConnection);
        DB::setDefaultConnection($matcherConnection);

        $matcher = DB::connection($matcherConnection);
        $matcher->beginTransaction();

        try {
            $matched = app(SubscriptionMatcher::class)->matchingActiveEndpointIds(EventType::fromString('order.paid'));
            self::assertSame([$endpointId], array_map(
                static fn ($id): string => $id->toString(),
                $matched,
            ));

            $updater = DB::connection($updaterConnection);
            $updater->statement('SET SESSION innodb_lock_wait_timeout = 1');

            try {
                $updater->table('endpoints')
                    ->where('public_id', $endpointId)
                    ->update(['status' => 'disabled']);
                self::fail('The matcher lock must prevent a concurrent disable.');
            } catch (QueryException $exception) {
                self::assertStringContainsString('1205', $exception->getMessage());
            }

            $event = app(CreateEvent::class)->handle('order.paid', (object) ['source' => 'matching-lock-test']);
            $matcher->commit();
        } finally {
            if ($matcher->transactionLevel() > 0) {
                $matcher->rollBack();
            }

            DB::setDefaultConnection($originalConnection);
            DB::purge($matcherConnection);
            DB::purge($updaterConnection);
        }

        self::assertSame(1, DB::connection($originalConnection)->table('deliveries')->count());
        self::assertSame(1, DB::connection($originalConnection)->table('events')
            ->where('public_id', $event->id)
            ->count());

        self::assertSame(1, DB::connection($updaterConnection)->table('endpoints')
            ->where('public_id', $endpointId)
            ->update(['status' => 'disabled']));
    }

    private function createEndpoint(): string
    {
        $response = $this->postJson('/api/endpoints', [
            'name' => 'Matching lock endpoint',
            'url' => 'https://example.test/webhooks/matching-lock',
        ])->assertCreated();

        return (string) $response->json('data.id');
    }
}

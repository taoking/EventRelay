<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Application\Event\CreateEvent;
use App\Application\Subscription\SubscriptionMatcher;
use App\Domain\Endpoint\EndpointId;
use App\Domain\Event\EventType;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class EventDeliveryMatchingConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_create_event_keeps_a_matched_endpoint_locked_until_delivery_creation_commits(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('This regression test requires MySQL/InnoDB row locks.');
        }

        $endpointId = $this->createEndpoint();
        $this->putJson("/api/endpoints/{$endpointId}/subscriptions", [
            'types' => ['order.paid'],
        ])->assertOk();

        $originalConnection = DB::getDefaultConnection();
        $creatorConnection = 'event_matching_creator';
        $updaterConnection = 'event_matching_updater';

        config([
            "database.connections.{$creatorConnection}" => config('database.connections.mysql'),
            "database.connections.{$updaterConnection}" => config('database.connections.mysql'),
        ]);
        DB::purge($creatorConnection);
        DB::purge($updaterConnection);

        try {
            $updater = DB::connection($updaterConnection);
            $updater->statement('SET SESSION innodb_lock_wait_timeout = 1');

            $realMatcher = app(SubscriptionMatcher::class);
            $mutationBlocked = false;

            $this->app->instance(
                SubscriptionMatcher::class,
                new class($realMatcher, function () use (&$mutationBlocked, $endpointId, $updater): void {
                    try {
                        $updater->table('endpoints')
                            ->where('public_id', $endpointId)
                            ->update(['status' => 'disabled']);
                        self::fail('The matcher lock must prevent a concurrent disable before CreateEvent commits.');
                    } catch (QueryException $exception) {
                        self::assertStringContainsString('1205', $exception->getMessage());
                        $mutationBlocked = true;
                    }
                }) implements SubscriptionMatcher
                {

                    public function __construct(
                        private readonly SubscriptionMatcher $matcher,
                        private readonly Closure $afterMatch,
                    ) {}

                    /**
                     * @return list<EndpointId>
                     */
                    public function matchingActiveEndpointIds(EventType $eventType): array
                    {
                        $endpointIds = $this->matcher->matchingActiveEndpointIds($eventType);

                        ($this->afterMatch)();

                        return $endpointIds;
                    }
                },
            );

            DB::setDefaultConnection($creatorConnection);
            $event = app(CreateEvent::class)->handle('order.paid', (object) ['source' => 'matching-lock-test']);
            self::assertTrue($mutationBlocked);
        } finally {
            DB::setDefaultConnection($originalConnection);
            DB::purge($creatorConnection);
            DB::purge($updaterConnection);
        }

        self::assertSame(1, DB::connection($originalConnection)->table('deliveries')->count());
        self::assertSame(1, DB::connection($originalConnection)->table('events')
            ->where('public_id', $event->id)
            ->count());
        self::assertSame(1, DB::connection($originalConnection)->table('deliveries')
            ->join('events', 'deliveries.event_id', '=', 'events.id')
            ->join('endpoints', 'deliveries.endpoint_id', '=', 'endpoints.id')
            ->where('events.public_id', $event->id)
            ->where('endpoints.public_id', $endpointId)
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

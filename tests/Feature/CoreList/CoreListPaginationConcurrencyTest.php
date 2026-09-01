<?php

declare(strict_types=1);

namespace Tests\Feature\CoreList;

use App\Application\CoreList\CoreListPage;
use App\Application\CoreList\CoreListPageRequest;
use App\Application\Delivery\DeliveryData;
use App\Application\Delivery\ListDeliveries;
use App\Application\Endpoint\EndpointData;
use App\Application\Endpoint\ListEndpoints;
use App\Application\Event\EventData;
use App\Application\Event\ListEvents;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CoreListPaginationConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_event_snapshot_excludes_rows_committed_after_page_one_without_duplicate_or_loss(): void
    {
        $this->requireMySqlAndPcntl();
        $oldIds = $this->seedEvents(5, 'event.concurrent');
        $first = app(ListEvents::class)->handle(new CoreListPageRequest(2, null));

        $this->runChild(function (): string {
            return $this->insertEvent('event.concurrent.new');
        });
        $this->reconnectAfterFork();

        $actual = $this->eventIds($this->traverseEvents($first, 2));
        self::assertSame($oldIds, $actual);
        self::assertSame(count($actual), count(array_unique($actual)));
    }

    public function test_delivery_snapshot_ignores_new_rows_while_returning_current_status_without_duplicate_or_loss(): void
    {
        $this->requireMySqlAndPcntl();
        $oldIds = $this->seedDeliveries(5, 'delivery.concurrent');
        $first = app(ListDeliveries::class)->handle(new CoreListPageRequest(2, null));

        $this->runChild(function () use ($oldIds): string {
            DB::table('deliveries')->where('public_id', $oldIds[3])->update(['status' => 'succeeded']);

            return $this->insertDelivery('delivery.concurrent.new');
        });
        $this->reconnectAfterFork();

        $pages = $this->traverseDeliveries($first, 2);
        $actual = $this->deliveryIds($pages);
        self::assertSame($oldIds, $actual);
        self::assertSame('succeeded', $pages[3]->status);
        self::assertSame(count($actual), count(array_unique($actual)));
    }

    public function test_same_timestamp_ties_keep_the_original_monotonic_creation_order_across_pages(): void
    {
        $this->requireMySqlAndPcntl();
        $ids = $this->seedEvents(5, 'event.tie', '2026-09-21 12:00:00');
        $first = app(ListEvents::class)->handle(new CoreListPageRequest(2, null));

        self::assertSame($ids, $this->eventIds($this->traverseEvents($first, 2)));
    }

    public function test_endpoint_snapshot_excludes_new_rows_and_hides_soft_deleted_rows_between_pages(): void
    {
        $this->requireMySqlAndPcntl();
        $oldIds = $this->seedEndpoints(5, 'endpoint.concurrent');
        $first = app(ListEndpoints::class)->handle(new CoreListPageRequest(2, null));

        $this->runChild(function () use ($oldIds): string {
            DB::table('endpoints')->where('public_id', $oldIds[3])->update(['deleted_at' => '2026-09-21 12:01:00']);

            return $this->insertEndpoint('endpoint.concurrent.new');
        });
        $this->reconnectAfterFork();

        $actual = $this->endpointIds($this->traverseEndpoints($first, 2));
        self::assertSame([$oldIds[0], $oldIds[1], $oldIds[2], $oldIds[4]], $actual);
        self::assertSame(count($actual), count(array_unique($actual)));
    }

    /** @return list<EventData> */
    private function traverseEvents(CoreListPage $first, int $limit): array
    {
        $items = $first->items;
        $cursor = $first->nextCursor;
        while ($cursor !== null) {
            $page = app(ListEvents::class)->handle(new CoreListPageRequest($limit, $cursor));
            array_push($items, ...$page->items);
            $cursor = $page->nextCursor;
        }

        return $items;
    }

    /** @return list<DeliveryData> */
    private function traverseDeliveries(CoreListPage $first, int $limit): array
    {
        $items = $first->items;
        $cursor = $first->nextCursor;
        while ($cursor !== null) {
            $page = app(ListDeliveries::class)->handle(new CoreListPageRequest($limit, $cursor));
            array_push($items, ...$page->items);
            $cursor = $page->nextCursor;
        }

        return $items;
    }

    /** @return list<EndpointData> */
    private function traverseEndpoints(CoreListPage $first, int $limit): array
    {
        $items = $first->items;
        $cursor = $first->nextCursor;
        while ($cursor !== null) {
            $page = app(ListEndpoints::class)->handle(new CoreListPageRequest($limit, $cursor));
            array_push($items, ...$page->items);
            $cursor = $page->nextCursor;
        }

        return $items;
    }

    /** @param list<EventData> $items
     * @return list<string>
     */
    private function eventIds(array $items): array
    {
        return array_map(static fn ($item): string => $item->id, $items);
    }

    /** @param list<DeliveryData> $items
     * @return list<string>
     */
    private function deliveryIds(array $items): array
    {
        return array_map(static fn ($item): string => $item->id, $items);
    }

    /** @param list<EndpointData> $items
     * @return list<string>
     */
    private function endpointIds(array $items): array
    {
        return array_map(static fn ($item): string => $item->id, $items);
    }

    /** @return list<string> */
    private function seedEvents(int $count, string $prefix, string $timestamp = '2026-09-21 12:00:00'): array
    {
        $ids = [];
        for ($index = 0; $index < $count; $index++) {
            $ids[] = $this->insertEvent("{$prefix}.{$index}", $timestamp);
        }

        return $ids;
    }

    /** @return list<string> */
    private function seedDeliveries(int $count, string $prefix): array
    {
        $ids = [];
        for ($index = 0; $index < $count; $index++) {
            $ids[] = $this->insertDelivery("{$prefix}.{$index}");
        }

        return $ids;
    }

    /** @return list<string> */
    private function seedEndpoints(int $count, string $prefix): array
    {
        $ids = [];
        for ($index = 0; $index < $count; $index++) {
            $ids[] = $this->insertEndpoint("{$prefix}.{$index}");
        }

        return $ids;
    }

    private function insertEvent(string $type, string $timestamp = '2026-09-21 12:00:00'): string
    {
        $id = (string) Str::uuid();
        DB::table('events')->insert(['public_id' => $id, 'type' => $type, 'payload' => '{}', 'created_at' => $timestamp]);

        return $id;
    }

    private function insertEndpoint(string $name, string $timestamp = '2026-09-21 12:00:00'): string
    {
        $id = (string) Str::uuid();
        DB::table('endpoints')->insert([
            'public_id' => $id,
            'name' => $name,
            'url' => 'https://receiver.example/'.$id,
            'status' => 'active',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $id;
    }

    private function insertDelivery(string $type): string
    {
        $eventId = $this->insertEvent($type);
        $endpointId = $this->insertEndpoint('Delivery '.$type);
        $deliveryId = (string) Str::uuid();
        DB::table('deliveries')->insert([
            'public_id' => $deliveryId,
            'event_id' => DB::table('events')->where('public_id', $eventId)->value('id'),
            'endpoint_id' => DB::table('endpoints')->where('public_id', $endpointId)->value('id'),
            'creation_key' => 'primary',
            'target_url' => 'https://receiver.example/'.$deliveryId,
            'status' => 'pending',
            'created_at' => '2026-09-21 12:00:00',
            'updated_at' => '2026-09-21 12:00:00',
        ]);

        return $deliveryId;
    }

    /** @param callable(): string $operation */
    private function runChild(callable $operation): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$parent, $child] = $pair;
        $pid = pcntl_fork();
        if ($pid === -1) {
            self::fail('Unable to fork the concurrent writer.');
        }
        if ($pid === 0) {
            fclose($parent);
            try {
                $this->reconnectAfterFork();
                if (fgets($child) !== "go\n") {
                    throw new \LogicException('The child did not receive the barrier release.');
                }
                fwrite($child, 'committed:'.$operation()."\n");
                fclose($child);
                exit(0);
            } catch (\Throwable $exception) {
                fwrite($child, 'error:'.get_class($exception)."\n");
                fclose($child);
                exit(1);
            }
        }

        fclose($child);
        stream_set_timeout($parent, 10);
        try {
            fwrite($parent, "go\n");
            self::assertStringStartsWith('committed:', (string) fgets($parent));
            pcntl_waitpid($pid, $status);
            self::assertSame(0, pcntl_wexitstatus($status));
        } finally {
            fclose($parent);
        }
    }

    private function requireMySqlAndPcntl(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('This regression test requires MySQL/InnoDB and pcntl.');
        }
    }

    private function reconnectAfterFork(): void
    {
        DB::disconnect();
        DB::purge();
        DB::connection()->getPdo();
    }
}

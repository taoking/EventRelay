<?php

declare(strict_types=1);

namespace Tests\Feature\DeadLetter;

use App\Application\DeadLetter\DeadLetterFilter;
use App\Application\DeadLetter\DeadLetterItem;
use App\Application\DeadLetter\ListDeadLetters;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DeadLetterPaginationConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_a_newer_failed_delivery_committed_after_page_one_does_not_duplicate_or_skip_the_old_cursor_window(): void
    {
        $this->requireMySqlAndPcntl();
        $failedAt = new DateTimeImmutable('2026-09-06T12:00:00+00:00');
        for ($index = 0; $index < 5; $index++) {
            $this->insertFailedDelivery("old.{$index}", $failedAt->modify("-{$index} seconds"));
        }

        $allBefore = $this->deliveryIds(app(ListDeadLetters::class)->handle(new DeadLetterFilter(null, null, null, null, 50), null)->items);
        $firstPage = app(ListDeadLetters::class)->handle(new DeadLetterFilter(null, null, null, null, 2), null);
        self::assertCount(2, $firstPage->items);
        self::assertNotNull($firstPage->nextCursor);

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$parent, $child] = $pair;
        $pid = pcntl_fork();
        if ($pid === -1) {
            self::fail('Unable to fork the concurrent dead-letter writer.');
        }
        if ($pid === 0) {
            fclose($parent);
            $this->insertNewerInChild($child, $failedAt->modify('+1 second'));
        }

        fclose($child);
        stream_set_timeout($parent, 10);

        try {
            fwrite($parent, "insert\n");
            self::assertStringStartsWith("inserted:\n", (string) fgets($parent));
            pcntl_waitpid($pid, $status);
            self::assertSame(0, pcntl_wexitstatus($status));

            $this->reconnectAfterFork();
            $secondPage = app(ListDeadLetters::class)->handle(
                new DeadLetterFilter(null, null, null, null, 2),
                $this->requiredCursor($firstPage->nextCursor),
            );

            self::assertSame(array_slice($allBefore, 0, 2), $this->deliveryIds($firstPage->items));
            self::assertSame(array_slice($allBefore, 2, 2), $this->deliveryIds($secondPage->items));
            self::assertEmpty(array_intersect($this->deliveryIds($firstPage->items), $this->deliveryIds($secondPage->items)));
        } finally {
            fclose($parent);
        }
    }

    public function test_identical_failed_at_values_use_uuid_descending_tie_break_across_pages(): void
    {
        $this->requireMySqlAndPcntl();
        $failedAt = new DateTimeImmutable('2026-09-06T13:00:00+00:00');
        $ids = [];
        for ($index = 0; $index < 5; $index++) {
            $ids[] = $this->insertFailedDelivery("same-time.{$index}", $failedAt);
        }
        rsort($ids, SORT_STRING);

        $filter = new DeadLetterFilter(null, null, null, null, 2);
        $first = app(ListDeadLetters::class)->handle($filter, null);
        $second = app(ListDeadLetters::class)->handle($filter, $this->requiredCursor($first->nextCursor));
        $third = app(ListDeadLetters::class)->handle($filter, $this->requiredCursor($second->nextCursor));

        self::assertSame($ids, array_merge(
            $this->deliveryIds($first->items),
            $this->deliveryIds($second->items),
            $this->deliveryIds($third->items),
        ));
    }

    /** @return never-return */
    private function insertNewerInChild(mixed $socket, DateTimeImmutable $failedAt): void
    {
        try {
            $this->reconnectAfterFork();
            if (fgets($socket) !== "insert\n") {
                throw new \LogicException('The child did not receive the insert barrier release.');
            }
            $this->insertFailedDelivery('newer.concurrent', $failedAt);
            fwrite($socket, "inserted:\n");
            fclose($socket);
            exit(0);
        } catch (\Throwable $exception) {
            fwrite($socket, 'error:'.get_class($exception)."\n");
            fclose($socket);
            exit(1);
        }
    }

    private function insertFailedDelivery(string $eventType, DateTimeImmutable $failedAt): string
    {
        $endpointId = (string) Str::uuid();
        $eventId = (string) Str::uuid();
        $deliveryId = (string) Str::uuid();
        $attemptId = (string) Str::uuid();
        $timestamp = $failedAt->format('Y-m-d H:i:s');
        DB::transaction(function () use ($endpointId, $eventId, $deliveryId, $attemptId, $eventType, $timestamp): void {
            $endpoint = DB::table('endpoints')->insertGetId([
                'public_id' => $endpointId,
                'name' => 'DLQ pagination endpoint '.$endpointId,
                'url' => 'https://receiver.example/dlq-pagination',
                'status' => 'active',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
            $event = DB::table('events')->insertGetId([
                'public_id' => $eventId,
                'type' => $eventType,
                'payload' => '{}',
                'created_at' => $timestamp,
            ]);
            $delivery = DB::table('deliveries')->insertGetId([
                'public_id' => $deliveryId,
                'event_id' => $event,
                'endpoint_id' => $endpoint,
                'creation_key' => 'primary',
                'target_url' => 'https://receiver.example/dlq-pagination',
                'status' => 'failed',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
            DB::table('delivery_attempts')->insert([
                'public_id' => $attemptId,
                'delivery_id' => $delivery,
                'attempt_number' => 1,
                'status' => 'failed',
                'response_status' => 400,
                'failure_type' => 'http_status',
                'failure_message' => 'DLQ pagination fixture.',
                'duration_ms' => 1,
                'started_at' => $timestamp,
                'finished_at' => $timestamp,
            ]);
        });

        return $deliveryId;
    }

    /** @param list<DeadLetterItem> $items
     * @return list<string>
     */
    private function deliveryIds(array $items): array
    {
        return array_map(static fn (DeadLetterItem $item): string => $item->deliveryId, $items);
    }

    private function requiredCursor(?string $cursor): string
    {
        if ($cursor === null) {
            self::fail('The previous page must include a next cursor.');
        }

        return $cursor;
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

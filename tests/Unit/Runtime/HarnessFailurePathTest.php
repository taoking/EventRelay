<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use Closure;
use PHPUnit\Framework\TestCase;
use Runtime\CleanupStack;

final class HarnessFailurePathTest extends TestCase
{
    public function test_scenario_exception_has_a_nonzero_result_and_runs_cleanup(): void
    {
        $cleaned = false;
        $exit = $this->execute(static function (): void {
            throw new \LogicException('controlled scenario failure');
        }, static function () use (&$cleaned): void {
            $cleaned = true;
        });

        self::assertSame(1, $exit);
        self::assertTrue($cleaned);
    }

    public function test_partial_startup_cleanup_is_idempotent_and_continues_after_an_error(): void
    {
        $calls = [];
        $cleanup = new CleanupStack;
        $cleanup->defer(static function () use (&$calls): void {
            $calls[] = 'first';
        });
        $cleanup->defer(static function () use (&$calls): void {
            $calls[] = 'failing';
            throw new \RuntimeException('controlled cleanup failure');
        });
        $cleanup->defer(static function () use (&$calls): void {
            $calls[] = 'last';
        });

        self::assertCount(1, $cleanup->run());
        self::assertSame(['last', 'failing', 'first'], $calls);
        self::assertSame([], $cleanup->run());
    }

    /** @param Closure(): void $scenario
     * @param  Closure(): void  $cleanup
     */
    private function execute(Closure $scenario, Closure $cleanup): int
    {
        try {
            $scenario();

            return 0;
        } catch (\Throwable) {
            return 1;
        } finally {
            $cleanup();
        }
    }
}

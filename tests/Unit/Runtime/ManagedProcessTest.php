<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Runtime\ManagedProcess;

final class ManagedProcessTest extends TestCase
{
    public function test_child_ignoring_term_is_killed_and_reaped(): void
    {
        if (! function_exists('pcntl_signal') || ! defined('SIGTERM') || ! defined('SIGKILL')) {
            $this->markTestSkipped('This regression test requires POSIX process signals.');
        }
        $child = new ManagedProcess([
            PHP_BINARY,
            '-r',
            'pcntl_async_signals(true); pcntl_signal(SIGTERM, static function (): void {}); while (true) { usleep(10000); }',
        ], dirname(__DIR__, 3), ['PATH' => (string) getenv('PATH')], 'controlled TERM-ignoring child');
        $child->start();

        try {
            self::assertTrue($child->isRunning());
            $child->terminate(0.1);
            self::assertFalse($child->isRunning());
            self::assertNotNull($child->exitCode());
        } finally {
            $child->terminate(0.1);
        }
    }
}

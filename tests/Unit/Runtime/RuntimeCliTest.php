<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class RuntimeCliTest extends TestCase
{
    public function test_unknown_scenario_returns_a_stable_nonzero_exit_code_without_starting_docker(): void
    {
        $process = new Process([PHP_BINARY, 'scripts/runtime.php', 'run', 'not-a-scenario'], dirname(__DIR__, 3));
        $process->run();

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('Unknown runtime scenario: not-a-scenario.', $process->getErrorOutput());
    }
}

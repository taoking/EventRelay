<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Runtime\OwnershipGuard;
use Runtime\ScenarioIdentity;

final class OwnershipGuardTest extends TestCase
{
    public function test_default_project_and_foreign_labels_are_rejected(): void
    {
        $guard = new OwnershipGuard;
        $unsafe = new ScenarioIdentity('local-a', 'local-a-isolation-smoke', 'eventrelay');

        $this->expectException(\RuntimeException::class);
        $guard->assertProject($unsafe);
    }

    public function test_wrong_run_label_and_non_allowlisted_service_are_rejected(): void
    {
        $guard = new OwnershipGuard;
        $identity = new ScenarioIdentity('local-a', 'local-a-isolation-smoke', 'eventrelay-runtime-local-a-isolation-smoke');

        try {
            $guard->assertLabels([
                'com.docker.compose.project' => $identity->project,
                'com.eventrelay.runtime' => 'true',
                'com.eventrelay.runtime.run-id' => 'different-run',
            ], $identity);
            self::fail('Expected foreign runtime label rejection.');
        } catch (\RuntimeException) {
            self::assertTrue(true);
        }

        $this->expectException(\RuntimeException::class);
        $guard->assertService('foreign-service');
    }
}

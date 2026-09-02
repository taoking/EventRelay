<?php

declare(strict_types=1);

namespace Runtime;

final readonly class OwnershipGuard
{
    /** @var list<string> */
    private const array Services = ['app', 'mysql', 'redis', 'rabbitmq'];

    public function assertProject(ScenarioIdentity $identity): void
    {
        if ($identity->project === 'eventrelay' || ! str_starts_with($identity->project, 'eventrelay-runtime-')) {
            throw new RuntimeException('Refusing an unsafe Docker Compose project.');
        }
    }

    public function assertService(string $service): void
    {
        if (! in_array($service, self::Services, true)) {
            throw new RuntimeException('Refusing a Docker operation for a non-runtime service.');
        }
    }

    /** @param array<string, string> $labels */
    public function assertLabels(array $labels, ScenarioIdentity $identity): void
    {
        $this->assertProject($identity);
        if (($labels['com.docker.compose.project'] ?? null) !== $identity->project
            || ($labels['com.eventrelay.runtime'] ?? null) !== 'true'
            || ($labels['com.eventrelay.runtime.run-id'] ?? null) !== $identity->runId) {
            throw new RuntimeException('Refusing an operation for a resource not owned by this runtime run.');
        }
    }
}

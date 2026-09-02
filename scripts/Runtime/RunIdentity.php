<?php

declare(strict_types=1);

namespace Runtime;

final readonly class RunIdentity
{
    private const string ProjectPrefix = 'eventrelay-runtime-';

    private function __construct(public string $baseRunId) {}

    public static function fromEnvironment(): self
    {
        $provided = getenv('EVENTRELAY_RUNTIME_RUN_ID');
        $runId = is_string($provided) && $provided !== ''
            ? $provided
            : 'local-'.bin2hex(random_bytes(8));

        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,22}[a-z0-9])?$/', $runId) || $runId === 'eventrelay') {
            throw new RuntimeException('EVENTRELAY_RUNTIME_RUN_ID must be 1..24 lowercase letters, digits or internal hyphens and must not be eventrelay.');
        }

        return new self($runId);
    }

    public function scenario(string $name): ScenarioIdentity
    {
        if (! preg_match('/^[a-z0-9-]{1,18}$/', $name)) {
            throw new RuntimeException('Runtime scenario name is invalid.');
        }

        $runId = $this->baseRunId.'-'.$name;
        $project = self::ProjectPrefix.$runId;
        if (strlen($project) > 63 || $project === 'eventrelay') {
            throw new RuntimeException('Runtime compose project is unsafe.');
        }

        return new ScenarioIdentity($this->baseRunId, $runId, $project);
    }
}

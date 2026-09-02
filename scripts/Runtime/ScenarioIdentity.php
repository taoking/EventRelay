<?php

declare(strict_types=1);

namespace Runtime;

final readonly class ScenarioIdentity
{
    public function __construct(
        public string $baseRunId,
        public string $runId,
        public string $project,
    ) {}
}

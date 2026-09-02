<?php

declare(strict_types=1);

namespace Runtime;

final readonly class IsolationSmokeScenario implements RuntimeScenario
{
    public function name(): string
    {
        return 'isolation-smoke';
    }

    public function transport(): string
    {
        return 'redis';
    }

    public function run(RuntimeContext $context): void
    {
        foreach (['app', 'mysql', 'redis', 'rabbitmq'] as $service) {
            $labels = $context->docker->labels($context->docker->serviceContainerId($service));
            (new OwnershipGuard)->assertLabels($labels, $context->identity);
        }
        $context->observe('live_status', 200);
        $context->observe('ready_status', 200);
        $context->observe('ownership', 'verified');
    }
}

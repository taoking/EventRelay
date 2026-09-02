<?php

declare(strict_types=1);

namespace Runtime;

final readonly class MySqlOutageScenario implements RuntimeScenario
{
    public function name(): string
    {
        return 'mysql-outage';
    }

    public function transport(): string
    {
        return 'redis';
    }

    public function run(RuntimeContext $context): void
    {
        $appBefore = $context->docker->serviceContainerId('app');
        $context->docker->stopService('mysql');
        $context->docker->waitForServiceStopped('mysql', $context->eventually());
        if ($context->request('GET', '/internal/health/live')->status !== 200) {
            throw new RuntimeException('Liveness did not remain available during a physical MySQL outage.');
        }
        $context->eventually()->until(
            fn (): bool => $context->request('GET', '/internal/health/ready')->status === 503,
            Deadline::afterSeconds(30),
            'readiness HTTP 503 during physical MySQL outage',
            $context->cancellation,
        );
        $context->docker->startService('mysql');
        $context->docker->waitForServiceHealth('mysql', $context->eventually());
        $context->eventually()->until(
            fn (): bool => $context->request('GET', '/internal/health/ready')->status === 200,
            Deadline::afterSeconds(60),
            'readiness HTTP 200 after physical MySQL recovery',
            $context->cancellation,
        );
        if ($context->docker->serviceContainerId('app') !== $appBefore) {
            throw new RuntimeException('App container identity changed during MySQL outage recovery.');
        }
        $context->observe('app_identity_unchanged', true);
        $context->observe('mysql_outage', 'physical-stop-start');
    }
}

<?php

declare(strict_types=1);

namespace Runtime;

final readonly class OutboxWorkerScenario implements RuntimeScenario
{
    public function name(): string
    {
        return 'outbox-worker';
    }

    public function transport(): string
    {
        return 'redis';
    }

    public function run(RuntimeContext $context): void
    {
        $firstDeliveryId = $context->createPendingDelivery('outbox-first');
        $secondDeliveryId = $context->createPendingDelivery('outbox-second');
        $worker = $context->docker->startArtisan(['outbox:work', '--limit=1', '--sleep=10'], 'continuous Outbox worker');
        $context->eventually()->until(function () use ($context, $worker): bool {
            $rows = $context->outboxRows();

            return $worker->isRunning()
                && count($rows) === 2
                && $rows[0]['status'] === 'published'
                && $rows[1]['status'] === 'pending'
                && $rows[1]['publication_attempts'] === 0;
        }, Deadline::afterSeconds(30), 'first Outbox worker cycle with second intent pending', $context->cancellation);
        $exitCode = $context->docker->terminateArtisan($worker, $context->eventually());
        $rows = $context->outboxRows();
        if ($exitCode !== 0
            || count($rows) !== 2
            || $rows[1]['status'] !== 'pending'
            || $rows[1]['publication_attempts'] !== 0
            || substr_count($worker->stdoutTail(), 'Outbox 工作轮次完成') !== 1
            || $context->processes->runningCount() !== 0) {
            throw new RuntimeException('Outbox worker SIGTERM started an unexpected second publication cycle or left an orphan.');
        }
        $context->observe('first_delivery_id', $firstDeliveryId);
        $context->observe('second_delivery_id', $secondDeliveryId);
        $context->observe('worker_exit', 0);
        $context->observe('second_intent_pending', true);
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Console\Commands;

use App\Application\Delivery\EnqueueDueRetries;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class EnqueueDueRetriesCommand extends Command
{
    protected $signature = 'deliveries:enqueue-due-retries {--limit=100 : 一次最多调度的到期 retry Delivery 数量（1-1000）}';

    protected $description = '为已到期 retry_scheduled Delivery 确保 durable Outbox 执行意图。';

    public function handle(EnqueueDueRetries $enqueueDueRetries): int
    {
        $option = $this->option('limit');

        if (! is_string($option) || filter_var($option, FILTER_VALIDATE_INT) === false) {
            $this->components->error('limit 必须是 1 到 1000 之间的整数。');

            return self::FAILURE;
        }

        try {
            $result = $enqueueDueRetries->handle((int) $option);
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf('到期 retry Outbox 意图确保完成：处理 %d。', $result->ensured));

        return self::SUCCESS;
    }
}

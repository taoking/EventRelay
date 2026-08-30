<?php

declare(strict_types=1);

namespace App\Infrastructure\Console\Commands;

use App\Application\Delivery\EnqueuePendingDeliveries;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class EnqueuePendingDeliveriesCommand extends Command
{
    protected $signature = 'deliveries:enqueue-pending {--limit=100 : 一次最多调度的 pending Delivery 数量（1-1000）}';

    protected $description = '将已有 pending Delivery 重新调度到 Redis deliveries 队列。';

    public function handle(EnqueuePendingDeliveries $enqueuePendingDeliveries): int
    {
        $option = $this->option('limit');

        if (! is_string($option) || filter_var($option, FILTER_VALIDATE_INT) === false) {
            $this->components->error('limit 必须是 1 到 1000 之间的整数。');

            return self::FAILURE;
        }

        try {
            $result = $enqueuePendingDeliveries->handle((int) $option);
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            '重新调度完成：成功 %d，发布失败 %d。',
            $result->enqueued,
            $result->failed,
        ));

        return self::SUCCESS;
    }
}

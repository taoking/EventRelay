<?php

declare(strict_types=1);

namespace App\Infrastructure\Console\Commands;

use App\Application\Delivery\RecoverStaleDeliveries;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class RecoverStaleDeliveriesCommand extends Command
{
    protected $signature = 'deliveries:recover-stale {--limit=100 : 一次最多恢复的 stale processing Delivery 数量（1-1000）}';

    protected $description = '将超过 stale threshold 的 processing Delivery 标为 abandoned 并按业务策略恢复。';

    public function handle(RecoverStaleDeliveries $recoverStaleDeliveries): int
    {
        $option = $this->option('limit');

        if (! is_string($option) || filter_var($option, FILTER_VALIDATE_INT) === false) {
            $this->components->error('limit 必须是 1 到 1000 之间的整数。');

            return self::FAILURE;
        }

        try {
            $result = $recoverStaleDeliveries->handle((int) $option);
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf('stale Delivery 恢复完成：已恢复 %d，已跳过 %d。', $result->enqueued, $result->failed));

        return self::SUCCESS;
    }
}

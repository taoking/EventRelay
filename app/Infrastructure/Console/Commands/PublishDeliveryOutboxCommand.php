<?php

declare(strict_types=1);

namespace App\Infrastructure\Console\Commands;

use App\Application\Delivery\PublishDeliveryOutbox;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class PublishDeliveryOutboxCommand extends Command
{
    protected $signature = 'outbox:publish {--limit=100 : 一次最多发布的 Outbox 消息数量（1-1000）}';

    protected $description = '将已提交的 Delivery Outbox 执行意图发布到 Redis deliveries 队列。';

    public function handle(PublishDeliveryOutbox $publisher): int
    {
        $option = $this->option('limit');
        if (! is_string($option) || filter_var($option, FILTER_VALIDATE_INT) === false) {
            $this->components->error('limit 必须是 1 到 1000 之间的整数。');

            return self::FAILURE;
        }

        try {
            $result = $publisher->handle((int) $option);
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Outbox 发布完成：成功 %d，Redis 发布失败 %d，lease 已丢失 %d。',
            $result->published,
            $result->failed,
            $result->lostLease,
        ));

        return self::SUCCESS;
    }
}

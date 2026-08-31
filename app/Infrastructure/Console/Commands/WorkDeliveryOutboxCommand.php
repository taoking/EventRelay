<?php

declare(strict_types=1);

namespace App\Infrastructure\Console\Commands;

use App\Application\Delivery\PublishDeliveryOutbox;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class WorkDeliveryOutboxCommand extends Command
{
    protected $signature = 'outbox:work
        {--limit=100 : 每轮最多发布的 Outbox 消息数量（1-1000）}
        {--sleep=1 : 空闲轮次休眠秒数（1-60）}
        {--once : 只执行一轮发布}';

    protected $description = '持续发布已到期的 Delivery Outbox 执行意图。';

    public function handle(PublishDeliveryOutbox $publisher): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        $sleep = filter_var($this->option('sleep'), FILTER_VALIDATE_INT);
        if (! is_int($limit) || $limit < 1 || $limit > PublishDeliveryOutbox::MaximumLimit) {
            $this->components->error('limit 必须是 1 到 1000 之间的整数。');

            return self::FAILURE;
        }

        if (! is_int($sleep) || $sleep < 1 || $sleep > 60) {
            $this->components->error('sleep 必须是 1 到 60 之间的整数。');

            return self::FAILURE;
        }

        $stop = false;
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, static function () use (&$stop): void {
                $stop = true;
            });
            pcntl_signal(SIGINT, static function () use (&$stop): void {
                $stop = true;
            });
        }

        while (true) {
            try {
                $result = $publisher->handle($limit);
            } catch (InvalidArgumentException $exception) {
                $this->components->error($exception->getMessage());

                return self::FAILURE;
            }

            $this->components->info(sprintf(
                'Outbox 工作轮次完成：成功 %d，传输层发布失败 %d，lease 已丢失 %d。',
                $result->published,
                $result->failed,
                $result->lostLease,
            ));

            if ($this->option('once') === true || $stop) {
                return self::SUCCESS;
            }

            sleep($sleep);
        }
    }
}

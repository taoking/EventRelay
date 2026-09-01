<?php

declare(strict_types=1);

namespace App\Infrastructure\Console\Commands;

use App\Infrastructure\RabbitMq\RabbitMqDeliveryConsumer;
use Illuminate\Console\Command;

final class ConsumeRabbitMqDeliveriesCommand extends Command
{
    protected $signature = 'deliveries:consume-rabbitmq
        {--once : 最多消费一条 RabbitMQ Delivery 消息}
        {--prefetch= : 未指定时使用 delivery.rabbitmq.prefetch}';

    protected $description = '手动确认消费 RabbitMQ Delivery 消息。';

    public function handle(RabbitMqDeliveryConsumer $consumer): int
    {
        $option = $this->option('prefetch');
        $prefetch = $option === null ? config('delivery.rabbitmq.prefetch') : filter_var($option, FILTER_VALIDATE_INT);
        if (! is_int($prefetch) || $prefetch < 1 || $prefetch > 1000) {
            $this->components->error('prefetch 必须是 1 到 1000 之间的整数。');

            return self::FAILURE;
        }

        if ($this->option('once') === true) {
            $consumer->consumeOnce($prefetch);

            return self::SUCCESS;
        }

        $stop = false;
        if (extension_loaded('pcntl')) {
            // php-amqplib 会在每次有界 select() 返回后派发待处理信号；关闭异步派发可避免
            // 信号中断 stream_select() 并在优雅退出时被误报为 AMQP I/O 等待失败。
            pcntl_async_signals(false);
            pcntl_signal(SIGTERM, static function () use (&$stop): void {
                $stop = true;
            });
            pcntl_signal(SIGINT, static function () use (&$stop): void {
                $stop = true;
            });
        }

        $consumer->consume($prefetch, static function () use (&$stop): bool {
            return $stop;
        });

        return self::SUCCESS;
    }
}

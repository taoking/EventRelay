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
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, static function () use (&$stop): void {
                $stop = true;
            });
            pcntl_signal(SIGINT, static function () use (&$stop): void {
                $stop = true;
            });
        }

        $consumer->consume($prefetch, static fn (): bool => $stop);

        return self::SUCCESS;
    }
}

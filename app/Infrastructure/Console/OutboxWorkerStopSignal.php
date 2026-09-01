<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

final class OutboxWorkerStopSignal
{
    private bool $requested = false;

    public function request(): void
    {
        $this->requested = true;
    }

    /**
     * 读取前派发待处理信号，可能由注册的回调改变停止状态。
     *
     * @phpstan-impure
     */
    public function requested(): bool
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }

        return $this->requested;
    }
}

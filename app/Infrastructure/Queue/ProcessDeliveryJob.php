<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue;

use App\Application\Delivery\ProcessPendingDelivery;
use App\Domain\Delivery\DeliveryId;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

final class ProcessDeliveryJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 10;

    public int $uniqueFor = 60;

    public function __construct(
        public readonly string $deliveryId,
    ) {
        $this->onConnection('redis');
        $this->onQueue('deliveries');
    }

    public function uniqueId(): string
    {
        return $this->deliveryId;
    }

    public function handle(ProcessPendingDelivery $processor): void
    {
        $deliveryId = DeliveryId::fromString($this->deliveryId);
        $processor->handle($deliveryId);

        Log::info('Delivery worker processing finished.', [
            'delivery_id' => $this->deliveryId,
            'worker' => 'redis',
        ]);
    }
}

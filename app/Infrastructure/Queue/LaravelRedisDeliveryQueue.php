<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue;

use App\Application\Delivery\DeliveryQueue;
use App\Application\Delivery\DeliveryQueueUnavailable;
use App\Domain\Delivery\DeliveryId;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Log;
use Predis\Connection\ConnectionException;
use Predis\Connection\Resource\Exception\StreamInitException;
use RedisException;

final readonly class LaravelRedisDeliveryQueue implements DeliveryQueue
{
    public function __construct(
        private Dispatcher $dispatcher,
    ) {}

    public function enqueue(DeliveryId $deliveryId): void
    {
        try {
            $this->dispatcher->dispatch(new ProcessDeliveryJob($deliveryId->toString()));
        } catch (ConnectionException|RedisException|StreamInitException $exception) {
            Log::warning('Delivery queue publication failed.', [
                'delivery_id' => $deliveryId->toString(),
                'queue' => 'deliveries',
                'connection' => 'redis',
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw new DeliveryQueueUnavailable($deliveryId, $exception);
        }
    }
}

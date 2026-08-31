<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue;

use App\Application\Delivery\DeliveryQueue;
use App\Application\Delivery\DeliveryQueueUnavailable;
use App\Domain\Delivery\DeliveryId;
use DateTimeImmutable;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Facades\Log;
use Predis\Connection\ConnectionException;
use Predis\Connection\Resource\Exception\StreamInitException;
use Predis\Response\ServerException;
use RedisException;

final class LaravelRedisDeliveryQueue implements DeliveryQueue
{
    public function enqueue(DeliveryId $deliveryId): void
    {
        $this->dispatch(new ProcessDeliveryJob($deliveryId->toString()), $deliveryId);
    }

    public function schedule(DeliveryId $deliveryId, DateTimeImmutable $availableAt): void
    {
        $job = (new ProcessDeliveryJob($deliveryId->toString()))->delay($availableAt);

        $this->dispatch($job, $deliveryId);
    }

    private function dispatch(ProcessDeliveryJob $job, DeliveryId $deliveryId): void
    {

        try {
            $pendingDispatch = new PendingDispatch($job);
            unset($pendingDispatch);
        } catch (ConnectionException|RedisException|ServerException|StreamInitException $exception) {
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

<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue;

use App\Application\Delivery\DeliveryQueue;
use App\Application\Delivery\DeliveryQueueUnavailable;
use App\Domain\Delivery\DeliveryId;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Facades\Log;
use Predis\Connection\ConnectionException;
use Predis\Connection\Resource\Exception\StreamInitException;
use Predis\Response\ServerException;
use RedisException;

final class LaravelRedisDeliveryQueue implements DeliveryQueue
{
    public function __construct(
        private readonly Cache $cache,
    ) {}

    public function enqueue(DeliveryId $deliveryId): void
    {
        $job = new ProcessDeliveryJob($deliveryId->toString());

        try {
            $pendingDispatch = new PendingDispatch($job);
            unset($pendingDispatch);
        } catch (ConnectionException|RedisException|ServerException|StreamInitException $exception) {
            $this->releaseUniqueLockAfterFailedPublication($job);

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

    private function releaseUniqueLockAfterFailedPublication(ProcessDeliveryJob $job): void
    {
        try {
            (new UniqueLock($this->cache))->release($job);
        } catch (ConnectionException|RedisException|ServerException|StreamInitException) {
            // 原 publication failure 仍是对 Application 可诊断的错误；未知 cleanup 错误不得吞掉。
        }
    }
}

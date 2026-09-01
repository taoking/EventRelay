<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue;

use App\Application\Delivery\DeliveryTransport;
use App\Application\Delivery\DeliveryTransportUnavailable;
use App\Domain\Delivery\DeliveryId;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Facades\Log;
use Predis\Connection\ConnectionException;
use Predis\Connection\Resource\Exception\StreamInitException;
use Predis\Response\ServerException;
use RedisException;

final class LaravelRedisDeliveryTransport implements DeliveryTransport
{
    public function publish(DeliveryId $deliveryId): void
    {
        try {
            $pendingDispatch = new PendingDispatch(new ProcessDeliveryJob($deliveryId->toString()));
            unset($pendingDispatch);
        } catch (ConnectionException|RedisException|ServerException|StreamInitException $exception) {
            Log::warning('Delivery transport publication failed.', [
                'delivery_id' => $deliveryId->toString(),
                'transport' => 'redis',
                'queue' => 'deliveries',
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw new DeliveryTransportUnavailable($deliveryId, 'redis', $exception);
        }
    }
}

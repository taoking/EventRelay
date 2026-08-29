<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Application\Delivery\CreateDelivery;
use App\Application\Delivery\DeliveryQueue;
use App\Application\Delivery\DeliveryQueueUnavailable;
use App\Application\Subscription\SubscriptionMatcher;
use App\Application\Transaction\TransactionManager;
use App\Domain\Delivery\DeliveryId;
use App\Domain\Event\Event;
use stdClass;

final readonly class CreateEvent
{
    public function __construct(
        private EventRepository $events,
        private SubscriptionMatcher $subscriptions,
        private CreateDelivery $deliveries,
        private TransactionManager $transactions,
        private DeliveryQueue $queue,
    ) {}

    public function handle(string $type, stdClass $payload): EventData
    {
        $created = $this->transactions->run(function () use ($type, $payload): CreatedEventDeliveries {
            $event = $this->events->save(Event::create($type, $payload));
            $deliveryIds = [];

            foreach ($this->subscriptions->matchingActiveEndpointIds($event->eventType()) as $endpointId) {
                $delivery = $this->deliveries->handle($event->id()->toString(), $endpointId->toString());
                $deliveryIds[] = DeliveryId::fromString($delivery->id);
            }

            return new CreatedEventDeliveries(EventData::fromDomain($event), $deliveryIds);
        });

        foreach ($created->deliveryIds as $deliveryId) {
            try {
                $this->queue->enqueue($deliveryId);
            } catch (DeliveryQueueUnavailable) {
                // MySQL 已提交；发布失败由 Infrastructure 记录，并由人工恢复入口重新调度。
            }
        }

        return $created->event;
    }
}

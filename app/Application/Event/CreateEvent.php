<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Application\Delivery\CreateDelivery;
use App\Application\Delivery\DeliveryExecutionIntent;
use App\Application\Delivery\DeliveryOutboxWriter;
use App\Application\Subscription\SubscriptionMatcher;
use App\Application\Transaction\TransactionManager;
use App\Domain\Delivery\DeliveryId;
use App\Domain\Event\Event;
use DateTimeImmutable;
use stdClass;

final readonly class CreateEvent
{
    public function __construct(
        private EventRepository $events,
        private SubscriptionMatcher $subscriptions,
        private CreateDelivery $deliveries,
        private TransactionManager $transactions,
        private DeliveryOutboxWriter $outbox,
    ) {}

    public function handle(string $type, stdClass $payload): EventData
    {
        return $this->transactions->run(function () use ($type, $payload): EventData {
            $event = $this->events->save(Event::create($type, $payload));

            foreach ($this->subscriptions->matchingActiveEndpointIds($event->eventType()) as $endpointId) {
                $delivery = $this->deliveries->handle($event->id()->toString(), $endpointId->toString());
                $this->outbox->schedule(
                    new DeliveryExecutionIntent(DeliveryId::fromString($delivery->id), 1, null),
                    new DateTimeImmutable($delivery->createdAt),
                );
            }

            return EventData::fromDomain($event);
        });
    }
}

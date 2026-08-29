<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Application\Delivery\CreateDelivery;
use App\Application\Subscription\SubscriptionMatcher;
use App\Application\Transaction\TransactionManager;
use App\Domain\Event\Event;
use stdClass;

final readonly class CreateEvent
{
    public function __construct(
        private EventRepository $events,
        private SubscriptionMatcher $subscriptions,
        private CreateDelivery $deliveries,
        private TransactionManager $transactions,
    ) {}

    public function handle(string $type, stdClass $payload): EventData
    {
        return $this->transactions->run(function () use ($type, $payload): EventData {
            $event = $this->events->save(Event::create($type, $payload));

            foreach ($this->subscriptions->matchingActiveEndpointIds($event->eventType()) as $endpointId) {
                $this->deliveries->handle($event->id()->toString(), $endpointId->toString());
            }

            return EventData::fromDomain($event);
        });
    }
}

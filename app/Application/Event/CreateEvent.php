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
        private EventIngressIdempotencyRepository $idempotencies,
        private SubscriptionMatcher $subscriptions,
        private CreateDelivery $deliveries,
        private TransactionManager $transactions,
        private DeliveryOutboxWriter $outbox,
    ) {}

    public function handle(string $type, stdClass $payload): EventData
    {
        return $this->handleWithIdempotency($type, $payload, null)->event;
    }

    public function handleWithIdempotency(string $type, stdClass $payload, ?string $idempotencyKey): CreatedEventResult
    {
        $key = EventIngressIdempotencyKey::fromOptional($idempotencyKey);

        if ($key === null) {
            return new CreatedEventResult($this->createEventGraph($type, $payload, null, null), true);
        }

        $fingerprint = EventIngressRequestFingerprint::from($type, $payload);

        try {
            return new CreatedEventResult(
                $this->createEventGraph($type, $payload, $key->digest(), $fingerprint->value()),
                true,
            );
        } catch (EventIngressIdempotencyAlreadyExists) {
            return $this->recoverExistingResult($key->digest(), $fingerprint->value());
        }
    }

    private function createEventGraph(
        string $type,
        stdClass $payload,
        ?string $keyDigest,
        ?string $requestFingerprint,
    ): EventData {
        return $this->transactions->run(function () use ($type, $payload, $keyDigest, $requestFingerprint): EventData {
            $event = $this->events->save(Event::create($type, $payload));

            if ($keyDigest !== null && $requestFingerprint !== null) {
                $this->idempotencies->create($keyDigest, $requestFingerprint, $event->id(), $event->createdAt());
            }

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

    private function recoverExistingResult(string $keyDigest, string $requestFingerprint): CreatedEventResult
    {
        return $this->transactions->run(function () use ($keyDigest, $requestFingerprint): CreatedEventResult {
            $binding = $this->idempotencies->findByKeyDigestForUpdate($keyDigest);
            if ($binding === null) {
                throw new \LogicException('Committed event ingress idempotency winner is missing.');
            }

            if (! hash_equals($binding->requestFingerprint, $requestFingerprint)) {
                throw new EventIngressIdempotencyConflict('The Idempotency-Key is already bound to a different event request.');
            }

            $event = $this->events->find($binding->eventId->toString());
            if ($event === null) {
                throw new \LogicException('Event ingress idempotency winner event is missing.');
            }

            return new CreatedEventResult(EventData::fromDomain($event), false);
        });
    }
}

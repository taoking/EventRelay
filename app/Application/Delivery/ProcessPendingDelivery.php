<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Application\Event\EventRepository;
use App\Domain\Delivery\DeliveryId;
use App\Domain\DeliveryAttempt\DeliveryFailureType;

final readonly class ProcessPendingDelivery
{
    public function __construct(
        private DeliveryRepository $deliveries,
        private DeliveryExecutionRepository $execution,
        private EventRepository $events,
        private WebhookTargetResolver $targets,
        private WebhookTransport $transport,
    ) {}

    public function handle(DeliveryId $deliveryId): void
    {
        if ($this->deliveries->find($deliveryId->toString()) === null) {
            throw new DeliveryNotFound($deliveryId->toString());
        }

        $claimed = $this->execution->claim($deliveryId);

        if ($claimed === null) {
            return;
        }

        $event = $this->events->find($claimed->delivery->eventId()->toString());

        if ($event === null) {
            throw new \LogicException('A claimed delivery must retain its event.');
        }

        $request = new WebhookRequest(
            json_encode([
                'id' => $event->id()->toString(),
                'type' => $event->type(),
                'created_at' => $event->createdAt()->format(DATE_ATOM),
                'payload' => $event->payload(),
            ], JSON_THROW_ON_ERROR),
            [
                'Content-Type' => 'application/json',
                'User-Agent' => 'EventRelay/0.1',
                'X-EventRelay-Event-Id' => $event->id()->toString(),
                'X-EventRelay-Delivery-Id' => $claimed->delivery->id()->toString(),
                'X-EventRelay-Attempt' => (string) $claimed->attempt->number(),
            ],
        );

        try {
            $response = $this->transport->send(
                $this->targets->resolve($claimed->delivery->targetUrl()),
                $request,
            );
        } catch (UnsafeWebhookTarget $exception) {
            $this->execution->finalize(
                $claimed->delivery->fail(),
                $claimed->attempt->fail(DeliveryFailureType::UnsafeTarget, $exception->getMessage(), null, 0),
            );

            return;
        } catch (WebhookTransportFailure $exception) {
            $this->execution->finalize(
                $claimed->delivery->fail(),
                $claimed->attempt->fail($exception->type, $exception->getMessage(), null, $exception->durationMs),
            );

            return;
        }

        if ($response->statusCode >= 200 && $response->statusCode <= 299) {
            $this->execution->finalize(
                $claimed->delivery->succeed(),
                $claimed->attempt->succeed($response->statusCode, $response->durationMs),
            );

            return;
        }

        $this->execution->finalize(
            $claimed->delivery->fail(),
            $claimed->attempt->fail(DeliveryFailureType::HttpStatus, "HTTP {$response->statusCode}", $response->statusCode, $response->durationMs),
        );
    }
}

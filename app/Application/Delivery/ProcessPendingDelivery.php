<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Application\Clock\Clock;
use App\Application\EndpointSigningSecret\EndpointSigningSecretRepository;
use App\Application\Event\EventRepository;
use App\Domain\Delivery\Delivery;
use App\Domain\Delivery\DeliveryId;
use App\Domain\DeliveryAttempt\DeliveryAttempt;
use App\Domain\DeliveryAttempt\DeliveryFailureType;

final readonly class ProcessPendingDelivery
{
    public function __construct(
        private DeliveryRepository $deliveries,
        private DeliveryExecutionRepository $execution,
        private EventRepository $events,
        private WebhookTargetResolver $targets,
        private WebhookTransport $transport,
        private DeliveryRetryPolicy $retryPolicy,
        private Clock $clock,
        private EndpointSigningSecretRepository $signingSecrets,
        private WebhookSigner $signer,
    ) {}

    public function handle(DeliveryId $deliveryId): void
    {
        if ($this->deliveries->find($deliveryId->toString()) === null) {
            throw new DeliveryNotFound($deliveryId->toString());
        }

        $claimed = $this->execution->claim($deliveryId, $this->clock->now());

        if ($claimed === null) {
            return;
        }

        $event = $this->events->find($claimed->delivery->eventId()->toString());

        if ($event === null) {
            throw new \LogicException('A claimed delivery must retain its event.');
        }

        $body = json_encode([
            'id' => $event->id()->toString(),
            'type' => $event->type(),
            'created_at' => $event->createdAt()->format(DATE_ATOM),
            'payload' => $event->payload(),
        ], JSON_THROW_ON_ERROR);
        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'EventRelay/0.1',
            'X-EventRelay-Event-Id' => $event->id()->toString(),
            'X-EventRelay-Delivery-Id' => $claimed->delivery->id()->toString(),
            'X-EventRelay-Attempt' => (string) $claimed->attempt->number(),
        ];

        // 签名失败是内部安全故障：在任何 target resolve / HTTP 之前传播，绝不降级为 unsigned。
        if ($claimed->delivery->signingSecretId() !== null) {
            $timestamp = $this->clock->now()->getTimestamp();
            $keyId = $claimed->delivery->signingSecretId();
            $signature = $this->signer->sign(
                $this->signingSecrets->plaintext($keyId),
                $timestamp,
                $claimed->delivery->id(),
                $claimed->attempt->number(),
                $body,
            );
            $headers['X-EventRelay-Signature'] = 'v1='.$signature;
            $headers['X-EventRelay-Timestamp'] = (string) $timestamp;
            $headers['X-EventRelay-Signing-Key-Id'] = $keyId->toString();
        }

        $request = new WebhookRequest(
            $body,
            $headers,
        );

        try {
            $response = $this->transport->send(
                $this->targets->resolve($claimed->delivery->targetUrl()),
                $request,
            );
        } catch (UnsafeWebhookTarget $exception) {
            $this->finalizeKnownFailure($claimed->delivery->id(), $claimed->delivery, $claimed->attempt, DeliveryFailureType::UnsafeTarget, $exception->getMessage(), null, 0);

            return;
        } catch (WebhookTransportFailure $exception) {
            $this->finalizeKnownFailure($claimed->delivery->id(), $claimed->delivery, $claimed->attempt, $exception->type, $exception->getMessage(), null, $exception->durationMs);

            return;
        }

        if ($response->statusCode >= 200 && $response->statusCode <= 299) {
            $this->execution->finalize(
                $claimed->delivery->succeed($this->clock->now()),
                $claimed->attempt->succeed($response->statusCode, $response->durationMs, $this->clock->now()),
            );

            return;
        }

        $this->finalizeKnownFailure(
            $claimed->delivery->id(),
            $claimed->delivery,
            $claimed->attempt,
            DeliveryFailureType::HttpStatus,
            "HTTP {$response->statusCode}",
            $response->statusCode,
            $response->durationMs,
        );
    }

    private function finalizeKnownFailure(
        DeliveryId $deliveryId,
        Delivery $delivery,
        DeliveryAttempt $attempt,
        DeliveryFailureType $type,
        string $message,
        ?int $responseStatus,
        int $durationMs,
    ): void {
        $now = $this->clock->now();
        $decision = $this->retryPolicy->forKnownFailure($attempt->number(), $type, $responseStatus);
        $availableAt = $decision->shouldRetry ? $now->modify("+{$decision->delaySeconds} seconds") : null;
        $finalDelivery = $availableAt === null
            ? $delivery->fail($now)
            : $delivery->scheduleRetry($availableAt, $now);

        $failedAttempt = $attempt->fail($type, $message, $responseStatus, $durationMs, $now);
        if ($availableAt === null) {
            $this->execution->finalize($finalDelivery, $failedAttempt);

            return;
        }

        $this->execution->finalizeAndScheduleRetry(
            $finalDelivery,
            $failedAttempt,
            new DeliveryExecutionIntent($deliveryId, $attempt->number() + 1, $availableAt),
        );
    }
}

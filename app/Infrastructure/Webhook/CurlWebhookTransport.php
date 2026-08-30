<?php

declare(strict_types=1);

namespace App\Infrastructure\Webhook;

use App\Application\Delivery\WebhookRequest;
use App\Application\Delivery\WebhookResponse;
use App\Application\Delivery\WebhookTarget;
use App\Application\Delivery\WebhookTransport;
use App\Application\Delivery\WebhookTransportFailure;
use App\Domain\DeliveryAttempt\DeliveryFailureType;

final class CurlWebhookTransport implements WebhookTransport
{
    public function __construct(
        private readonly CurlTransportDriver $curl,
    ) {}

    public function send(WebhookTarget $target, WebhookRequest $request): WebhookResponse
    {
        $handle = $this->curl->init($target->url);

        if ($handle === false) {
            throw new \LogicException('Unable to initialise webhook cURL transport.');
        }

        $headers = [];
        foreach ($request->headers as $name => $value) {
            $headers[] = "{$name}: {$value}";
        }

        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $request->body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_WRITEFUNCTION => static fn ($handle, string $data): int => strlen($data),
        ];

        if (! $target->isIpLiteral) {
            $options[CURLOPT_RESOLVE] = [self::resolveEntry($target)];
        }

        if (! $this->curl->setOptions($handle, $options)) {
            throw new \LogicException('Unable to install secure webhook cURL options.');
        }

        $startedAt = hrtime(true);
        $result = $this->curl->execute($handle);
        $durationMs = (int) floor((hrtime(true) - $startedAt) / 1_000_000);
        $errno = $this->curl->errno($handle);
        $error = $this->curl->error($handle);
        $status = $this->curl->responseCode($handle);

        if ($result === false) {
            throw new WebhookTransportFailure(
                $errno === CURLE_OPERATION_TIMEDOUT ? DeliveryFailureType::Timeout : DeliveryFailureType::NetworkError,
                $error === '' ? 'Webhook transport failed.' : $error,
                $durationMs,
            );
        }

        return new WebhookResponse($status, $durationMs);
    }

    public static function resolveEntry(WebhookTarget $target): string
    {
        $address = filter_var($target->ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false
            ? $target->ip
            : "[{$target->ip}]";

        return "{$target->host}:{$target->port}:{$address}";
    }
}

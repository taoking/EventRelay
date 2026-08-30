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
    public function send(WebhookTarget $target, WebhookRequest $request): WebhookResponse
    {
        $handle = curl_init($target->url);

        if ($handle === false) {
            throw new \LogicException('Unable to initialise webhook cURL transport.');
        }

        $headers = [];
        foreach ($request->headers as $name => $value) {
            $headers[] = "{$name}: {$value}";
        }

        curl_setopt_array($handle, [
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
            CURLOPT_RESOLVE => ["{$target->host}:{$target->port}:{$target->ip}"],
            CURLOPT_WRITEFUNCTION => static fn ($handle, string $data): int => strlen($data),
        ]);

        $startedAt = hrtime(true);
        $result = curl_exec($handle);
        $durationMs = (int) floor((hrtime(true) - $startedAt) / 1_000_000);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        if ($result === false) {
            throw new WebhookTransportFailure(
                $errno === CURLE_OPERATION_TIMEDOUT ? DeliveryFailureType::Timeout : DeliveryFailureType::NetworkError,
                $error === '' ? 'Webhook transport failed.' : $error,
                $durationMs,
            );
        }

        return new WebhookResponse($status, $durationMs);
    }
}

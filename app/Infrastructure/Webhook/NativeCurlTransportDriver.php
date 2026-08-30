<?php

declare(strict_types=1);

namespace App\Infrastructure\Webhook;

final class NativeCurlTransportDriver implements CurlTransportDriver
{
    public function init(string $url): object|false
    {
        return curl_init($url);
    }

    public function setOptions(object $handle, array $options): bool
    {
        return curl_setopt_array($this->handle($handle), $options);
    }

    public function execute(object $handle): string|bool
    {
        return curl_exec($this->handle($handle));
    }

    public function errno(object $handle): int
    {
        return curl_errno($this->handle($handle));
    }

    public function error(object $handle): string
    {
        return curl_error($this->handle($handle));
    }

    public function responseCode(object $handle): int
    {
        return (int) curl_getinfo($this->handle($handle), CURLINFO_RESPONSE_CODE);
    }

    private function handle(object $handle): \CurlHandle
    {
        if (! $handle instanceof \CurlHandle) {
            throw new \LogicException('Native cURL transport received an invalid handle.');
        }

        return $handle;
    }
}

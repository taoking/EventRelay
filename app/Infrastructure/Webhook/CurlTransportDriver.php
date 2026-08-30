<?php

declare(strict_types=1);

namespace App\Infrastructure\Webhook;

interface CurlTransportDriver
{
    public function init(string $url): object|false;

    /**
     * @param  array<int, mixed>  $options
     */
    public function setOptions(object $handle, array $options): bool;

    public function execute(object $handle): string|bool;

    public function errno(object $handle): int;

    public function error(object $handle): string;

    public function responseCode(object $handle): int;
}

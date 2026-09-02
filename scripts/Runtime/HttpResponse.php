<?php

declare(strict_types=1);

namespace Runtime;

final readonly class HttpResponse
{
    /** @param array<string, string> $headers */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers,
    ) {}

    /** @return array<string, mixed> */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Expected a JSON object response.');
        }

        return $decoded;
    }
}

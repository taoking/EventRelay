<?php

declare(strict_types=1);

namespace Runtime;

final readonly class Redactor
{
    /** @param list<string> $secrets */
    public function __construct(private array $secrets = []) {}

    public function redact(string $value): string
    {
        foreach ($this->secrets as $secret) {
            if ($secret !== '') {
                $value = str_replace($secret, 'REDACTED', $value);
            }
        }

        $patterns = [
            '/(Authorization:\s*Bearer\s+)[^\s]+/i',
            '/(OPERATIONS_BEARER_TOKEN=)[^\s]+/i',
            '/(DB_PASSWORD=)[^\s]+/i',
            '/(RABBITMQ_PASSWORD=)[^\s]+/i',
            '/(mysql:\/\/[^:\s]+:)[^@\s]+@/i',
            '/whsec_[A-Za-z0-9_-]+/',
        ];

        foreach ($patterns as $pattern) {
            $value = (string) preg_replace($pattern, '$1REDACTED', $value);
        }

        return $value;
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations;

use App\Application\Operations\OperationsEndpointAccess;
use LogicException;

final readonly class OperationsEndpointConfiguration implements OperationsEndpointAccess
{
    private function __construct(
        public bool $enabled,
        private string $bearerToken,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        $enabled = $config['enabled'] ?? false;
        $token = $config['bearer_token'] ?? '';
        if (! is_bool($enabled) || ! is_string($token)) {
            throw new LogicException('Operations endpoint configuration is invalid.');
        }

        if ($enabled && trim($token) === '') {
            throw new LogicException('OPERATIONS_BEARER_TOKEN must be configured when operations endpoints are enabled.');
        }

        return new self($enabled, $token);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function accepts(?string $token): bool
    {
        return is_string($token) && hash_equals($this->bearerToken, $token);
    }
}

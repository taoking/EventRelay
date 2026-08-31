<?php

declare(strict_types=1);

namespace App\Infrastructure\RabbitMq;

use LogicException;

final readonly class RabbitMqConfiguration
{
    public function __construct(
        public string $host,
        public int $port,
        public string $user,
        public string $password,
        public string $vhost,
        public string $exchange,
        public string $queue,
        public string $routingKey,
        public int $prefetch,
        public float $timeout,
    ) {
        if ($host === '' || $user === '' || $vhost === '' || $exchange === '' || $queue === '' || $routingKey === '') {
            throw new LogicException('RabbitMQ configuration contains a required empty value.');
        }

        if ($port < 1 || $port > 65535 || $prefetch < 1 || $prefetch > 1000 || $timeout <= 0) {
            throw new LogicException('RabbitMQ configuration contains an invalid numeric value.');
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            self::string($config, 'host'),
            self::integer($config, 'port'),
            self::string($config, 'user'),
            self::string($config, 'password'),
            self::string($config, 'vhost'),
            self::string($config, 'exchange'),
            self::string($config, 'queue'),
            self::string($config, 'routing_key'),
            self::integer($config, 'prefetch'),
            self::float($config, 'timeout'),
        );
    }

    /** @param array<string, mixed> $config */
    private static function string(array $config, string $key): string
    {
        $value = $config[$key] ?? null;

        if (! is_string($value)) {
            throw new LogicException(sprintf('RabbitMQ configuration %s must be a string.', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $config */
    private static function integer(array $config, string $key): int
    {
        $value = $config[$key] ?? null;

        if (! is_int($value)) {
            throw new LogicException(sprintf('RabbitMQ configuration %s must be an integer.', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $config */
    private static function float(array $config, string $key): float
    {
        $value = $config[$key] ?? null;

        if (! is_float($value) && ! is_int($value)) {
            throw new LogicException(sprintf('RabbitMQ configuration %s must be numeric.', $key));
        }

        return (float) $value;
    }
}

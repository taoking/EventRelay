<?php

declare(strict_types=1);

namespace App\Domain\Endpoint;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

final readonly class Endpoint
{
    private function __construct(
        private EndpointId $id,
        private string $name,
        private string $url,
        private EndpointStatus $status,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {}

    public static function create(string $name, string $url, EndpointStatus $status): self
    {
        $now = new DateTimeImmutable;

        return new self(
            EndpointId::generate(),
            self::normaliseName($name),
            self::normaliseUrl($url),
            $status,
            $now,
            $now,
        );
    }

    public static function reconstitute(
        EndpointId $id,
        string $name,
        string $url,
        EndpointStatus $status,
        DateTimeInterface $createdAt,
        DateTimeInterface $updatedAt,
    ): self {
        return new self(
            $id,
            self::normaliseName($name),
            self::normaliseUrl($url),
            $status,
            DateTimeImmutable::createFromInterface($createdAt),
            DateTimeImmutable::createFromInterface($updatedAt),
        );
    }

    public function update(?string $name, ?string $url, ?EndpointStatus $status): self
    {
        return new self(
            $this->id,
            $name === null ? $this->name : self::normaliseName($name),
            $url === null ? $this->url : self::normaliseUrl($url),
            $status ?? $this->status,
            $this->createdAt,
            new DateTimeImmutable,
        );
    }

    public function id(): EndpointId
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function status(): EndpointStatus
    {
        return $this->status;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private static function normaliseName(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('Endpoint name is required.');
        }

        if (mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Endpoint name may not be longer than 120 characters.');
        }

        return $name;
    }

    private static function normaliseUrl(string $url): string
    {
        $url = trim($url);
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (
            $url === ''
            || mb_strlen($url) > 2048
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || ! is_string($scheme)
            || ! in_array(strtolower($scheme), ['http', 'https'], true)
        ) {
            throw new InvalidArgumentException('Endpoint URL must be a valid HTTP or HTTPS URL.');
        }

        return $url;
    }
}

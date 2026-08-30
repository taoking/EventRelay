<?php

declare(strict_types=1);

namespace App\Domain\EndpointSigningSecret;

use App\Domain\Endpoint\EndpointId;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * 端点签名密钥的领域元数据；明文和密文均不属于领域对象。
 */
final readonly class EndpointSigningSecret
{
    public function __construct(
        private EndpointSigningSecretId $id,
        private EndpointId $endpointId,
        private int $version,
        private DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $retiredAt,
    ) {
        if ($version < 1) {
            throw new \LogicException('Signing secret version must be at least one.');
        }
    }

    public static function reconstitute(
        EndpointSigningSecretId $id,
        EndpointId $endpointId,
        int $version,
        DateTimeInterface $createdAt,
        ?DateTimeInterface $retiredAt,
    ): self {
        return new self(
            $id,
            $endpointId,
            $version,
            DateTimeImmutable::createFromInterface($createdAt),
            $retiredAt === null ? null : DateTimeImmutable::createFromInterface($retiredAt),
        );
    }

    public function id(): EndpointSigningSecretId
    {
        return $this->id;
    }

    public function endpointId(): EndpointId
    {
        return $this->endpointId;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function retiredAt(): ?DateTimeImmutable
    {
        return $this->retiredAt;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\DeliveryAttempt;

use App\Domain\Delivery\DeliveryId;
use DateTimeImmutable;
use DateTimeInterface;

final readonly class DeliveryAttempt
{
    private function __construct(
        private DeliveryAttemptId $id,
        private DeliveryId $deliveryId,
        private int $number,
        private DeliveryAttemptStatus $status,
        private ?int $responseStatus,
        private ?DeliveryFailureType $failureType,
        private ?string $failureMessage,
        private ?int $durationMs,
        private DateTimeImmutable $startedAt,
        private ?DateTimeImmutable $finishedAt,
    ) {
        if ($number < 1) {
            throw new \LogicException('Delivery attempt number must be at least one.');
        }
    }

    public static function start(DeliveryId $deliveryId, int $number = 1, ?DateTimeInterface $startedAt = null): self
    {
        return new self(
            DeliveryAttemptId::generate(),
            $deliveryId,
            $number,
            DeliveryAttemptStatus::Started,
            null,
            null,
            null,
            null,
            $startedAt === null ? new DateTimeImmutable : DateTimeImmutable::createFromInterface($startedAt),
            null,
        );
    }

    public static function reconstitute(
        DeliveryAttemptId $id,
        DeliveryId $deliveryId,
        int $number,
        DeliveryAttemptStatus $status,
        ?int $responseStatus,
        ?DeliveryFailureType $failureType,
        ?string $failureMessage,
        ?int $durationMs,
        DateTimeInterface $startedAt,
        ?DateTimeInterface $finishedAt,
    ): self {
        return new self(
            $id,
            $deliveryId,
            $number,
            $status,
            $responseStatus,
            $failureType,
            $failureMessage,
            $durationMs,
            DateTimeImmutable::createFromInterface($startedAt),
            $finishedAt === null ? null : DateTimeImmutable::createFromInterface($finishedAt),
        );
    }

    public function succeed(int $statusCode, int $durationMs, ?DateTimeInterface $finishedAt = null): self
    {
        $this->assertStarted();

        return new self($this->id, $this->deliveryId, $this->number, DeliveryAttemptStatus::Succeeded, $statusCode, null, null, $durationMs, $this->startedAt, $finishedAt === null ? new DateTimeImmutable : DateTimeImmutable::createFromInterface($finishedAt));
    }

    public function fail(DeliveryFailureType $type, string $message, ?int $statusCode, int $durationMs, ?DateTimeInterface $finishedAt = null): self
    {
        $this->assertStarted();

        return new self($this->id, $this->deliveryId, $this->number, DeliveryAttemptStatus::Failed, $statusCode, $type, mb_substr($message, 0, 500), $durationMs, $this->startedAt, $finishedAt === null ? new DateTimeImmutable : DateTimeImmutable::createFromInterface($finishedAt));
    }

    public function abandon(string $message, ?DateTimeInterface $finishedAt = null): self
    {
        $this->assertStarted();

        return new self(
            $this->id,
            $this->deliveryId,
            $this->number,
            DeliveryAttemptStatus::Abandoned,
            null,
            DeliveryFailureType::StaleProcessing,
            mb_substr($message, 0, 500),
            null,
            $this->startedAt,
            $finishedAt === null ? new DateTimeImmutable : DateTimeImmutable::createFromInterface($finishedAt),
        );
    }

    private function assertStarted(): void
    {
        if ($this->status !== DeliveryAttemptStatus::Started) {
            throw new \LogicException('Only a started delivery attempt can be finalized.');
        }
    }

    public function id(): DeliveryAttemptId
    {
        return $this->id;
    }

    public function deliveryId(): DeliveryId
    {
        return $this->deliveryId;
    }

    public function number(): int
    {
        return $this->number;
    }

    public function status(): DeliveryAttemptStatus
    {
        return $this->status;
    }

    public function responseStatus(): ?int
    {
        return $this->responseStatus;
    }

    public function failureType(): ?DeliveryFailureType
    {
        return $this->failureType;
    }

    public function failureMessage(): ?string
    {
        return $this->failureMessage;
    }

    public function durationMs(): ?int
    {
        return $this->durationMs;
    }

    public function startedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function finishedAt(): ?DateTimeImmutable
    {
        return $this->finishedAt;
    }
}

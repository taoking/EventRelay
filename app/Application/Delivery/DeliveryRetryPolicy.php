<?php

declare(strict_types=1);

namespace App\Application\Delivery;

use App\Domain\DeliveryAttempt\DeliveryFailureType;

final class DeliveryRetryPolicy
{
    public const int MAX_ATTEMPTS = 3;

    public function forKnownFailure(int $attemptNumber, DeliveryFailureType $failureType, ?int $responseStatus): RetryDecision
    {
        if ($attemptNumber >= self::MAX_ATTEMPTS || ! $this->isRetryable($failureType, $responseStatus)) {
            return RetryDecision::terminal();
        }

        return RetryDecision::retryAfter($attemptNumber === 1 ? 10 : 60);
    }

    public function forStaleProcessing(int $attemptNumber): RetryDecision
    {
        if ($attemptNumber >= self::MAX_ATTEMPTS) {
            return RetryDecision::terminal();
        }

        return RetryDecision::retryAfter($attemptNumber === 1 ? 10 : 60);
    }

    private function isRetryable(DeliveryFailureType $failureType, ?int $responseStatus): bool
    {
        return match ($failureType) {
            DeliveryFailureType::Timeout, DeliveryFailureType::NetworkError => true,
            DeliveryFailureType::HttpStatus => $responseStatus === 408
                || $responseStatus === 429
                || ($responseStatus !== null && $responseStatus >= 500 && $responseStatus <= 599),
            DeliveryFailureType::UnsafeTarget, DeliveryFailureType::StaleProcessing => false,
        };
    }
}

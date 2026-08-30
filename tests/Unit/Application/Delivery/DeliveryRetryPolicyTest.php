<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Delivery;

use App\Application\Delivery\DeliveryRetryPolicy;
use App\Domain\DeliveryAttempt\DeliveryFailureType;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class DeliveryRetryPolicyTest extends TestCase
{
    #[DataProvider('retryableFailures')]
    public function test_it_retries_only_the_explicit_retryable_failure_matrix(DeliveryFailureType $type, ?int $status): void
    {
        $policy = new DeliveryRetryPolicy;

        self::assertTrue($policy->forKnownFailure(1, $type, $status)->shouldRetry);
    }

    #[DataProvider('terminalFailures')]
    public function test_it_makes_non_retryable_failures_terminal(DeliveryFailureType $type, ?int $status): void
    {
        $policy = new DeliveryRetryPolicy;

        self::assertFalse($policy->forKnownFailure(1, $type, $status)->shouldRetry);
    }

    public function test_it_uses_the_production_backoff_and_never_allows_a_fourth_attempt(): void
    {
        $policy = new DeliveryRetryPolicy;

        self::assertSame(10, $policy->forKnownFailure(1, DeliveryFailureType::HttpStatus, 500)->delaySeconds);
        self::assertSame(60, $policy->forKnownFailure(2, DeliveryFailureType::Timeout, null)->delaySeconds);
        self::assertFalse($policy->forKnownFailure(3, DeliveryFailureType::NetworkError, null)->shouldRetry);
        self::assertFalse($policy->forStaleProcessing(3)->shouldRetry);
    }

    /**
     * @return array<string, array{DeliveryFailureType, int|null}>
     */
    public static function retryableFailures(): array
    {
        return [
            'timeout' => [DeliveryFailureType::Timeout, null],
            'network error' => [DeliveryFailureType::NetworkError, null],
            'HTTP 408' => [DeliveryFailureType::HttpStatus, 408],
            'HTTP 429' => [DeliveryFailureType::HttpStatus, 429],
            'HTTP 500' => [DeliveryFailureType::HttpStatus, 500],
            'HTTP 599' => [DeliveryFailureType::HttpStatus, 599],
        ];
    }

    /**
     * @return array<string, array{DeliveryFailureType, int|null}>
     */
    public static function terminalFailures(): array
    {
        return [
            'unsafe target' => [DeliveryFailureType::UnsafeTarget, null],
            'HTTP 301' => [DeliveryFailureType::HttpStatus, 301],
            'HTTP 400' => [DeliveryFailureType::HttpStatus, 400],
            'HTTP 404' => [DeliveryFailureType::HttpStatus, 404],
            'HTTP 422' => [DeliveryFailureType::HttpStatus, 422],
        ];
    }
}

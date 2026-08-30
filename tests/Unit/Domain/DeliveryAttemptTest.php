<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Delivery\DeliveryId;
use App\Domain\DeliveryAttempt\DeliveryAttempt;
use App\Domain\DeliveryAttempt\DeliveryAttemptStatus;
use App\Domain\DeliveryAttempt\DeliveryFailureType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DeliveryAttemptTest extends TestCase
{
    #[Test]
    public function it_starts_the_first_attempt_with_a_public_uuid_v4(): void
    {
        $attempt = DeliveryAttempt::start(DeliveryId::generate());

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $attempt->id()->toString(),
        );
        self::assertSame(1, $attempt->number());
        self::assertSame(DeliveryAttemptStatus::Started, $attempt->status());
        self::assertNull($attempt->finishedAt());
    }

    #[Test]
    public function it_records_terminal_http_and_safe_bounded_failure_details(): void
    {
        $attempt = DeliveryAttempt::start(DeliveryId::generate());
        $succeeded = $attempt->succeed(204, 12);
        $failed = $attempt->fail(DeliveryFailureType::NetworkError, str_repeat('x', 800), null, 23);

        self::assertSame(DeliveryAttemptStatus::Succeeded, $succeeded->status());
        self::assertSame(204, $succeeded->responseStatus());
        self::assertSame(12, $succeeded->durationMs());
        self::assertNotNull($succeeded->finishedAt());

        self::assertSame(DeliveryAttemptStatus::Failed, $failed->status());
        self::assertSame(DeliveryFailureType::NetworkError, $failed->failureType());
        self::assertSame(500, mb_strlen((string) $failed->failureMessage()));
        self::assertSame(23, $failed->durationMs());
    }
}

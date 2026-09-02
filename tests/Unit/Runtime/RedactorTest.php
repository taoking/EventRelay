<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Runtime\Redactor;

final class RedactorTest extends TestCase
{
    public function test_diagnostics_redact_marker_secret_and_authorization(): void
    {
        $secret = 'runtime-marker-secret';
        $rendered = (new Redactor([$secret]))->redact("Authorization: Bearer {$secret}\nDB_PASSWORD={$secret}\n{$secret}");

        self::assertStringNotContainsString($secret, $rendered);
        self::assertStringContainsString('REDACTED', $rendered);
    }
}

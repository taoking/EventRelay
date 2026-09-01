<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Operations;

use App\Application\Operations\OperationalSnapshot;
use App\Infrastructure\Operations\PrometheusOperationalMetricsRenderer;
use Tests\TestCase;

final class PrometheusOperationalMetricsRendererTest extends TestCase
{
    public function test_renderer_emits_deterministic_utf8_lf_terminated_prometheus_text(): void
    {
        $snapshot = new OperationalSnapshot(
            [
                'pending' => 1,
                'processing' => 2,
                'retry_scheduled' => 3,
                'succeeded' => 4,
                'failed' => 5,
            ],
            [
                'pending' => 6,
                'publishing' => 7,
                'published' => 8,
            ],
            9,
            10,
            11,
            12,
        );

        $body = (new PrometheusOperationalMetricsRenderer)->render($snapshot, 'rabbitmq');

        self::assertSame($body, (new PrometheusOperationalMetricsRenderer)->render($snapshot, 'rabbitmq'));
        self::assertStringEndsWith("\n", $body);
        self::assertStringNotContainsString("\r\n", $body);
        self::assertStringContainsString('eventrelay_build_info{transport="rabbitmq"} 1', $body);
        self::assertStringContainsString('eventrelay_deliveries{status="failed"} 5', $body);
        self::assertStringContainsString('eventrelay_outbox_messages{status="published"} 8', $body);
        self::assertStringContainsString('eventrelay_dead_letters 5', $body);
    }
}

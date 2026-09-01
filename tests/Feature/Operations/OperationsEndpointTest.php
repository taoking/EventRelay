<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Application\Operations\OperationalDataUnavailable;
use App\Application\Operations\OperationalReadinessRepository;
use App\Application\Operations\OperationalSnapshot;
use App\Application\Operations\OperationalSnapshotRepository;
use App\Infrastructure\Operations\OperationsEndpointConfiguration;
use App\Infrastructure\Operations\PrometheusOperationalMetricsRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PDOException;
use Tests\TestCase;

final class OperationsEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_operations_endpoints_are_not_routable_when_disabled(): void
    {
        $this->get('/internal/health/live')->assertNotFound();
        $this->get('/internal/health/ready')->assertNotFound();
        $this->get('/internal/metrics')->assertNotFound();
    }

    public function test_enabled_operations_endpoints_require_a_correct_bearer_token_without_echoing_it(): void
    {
        $token = 'operations-test-token';
        $this->enableOperations($token);

        $this->get('/internal/health/live')
            ->assertUnauthorized()
            ->assertJson(['message' => 'Unauthorized.'])
            ->assertDontSee($token);
        $this->withToken('wrong-token')->get('/internal/health/live')
            ->assertUnauthorized()
            ->assertDontSee($token);
        $this->withToken($token)->get('/internal/health/live')
            ->assertOk()
            ->assertExactJson(['status' => 'alive'])
            ->assertDontSee($token);
    }

    public function test_enabled_configuration_without_a_token_fails_fast(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('OPERATIONS_BEARER_TOKEN must be configured');

        OperationsEndpointConfiguration::fromConfig([
            'enabled' => true,
            'bearer_token' => '',
        ]);
    }

    public function test_liveness_does_not_resolve_or_probe_mysql(): void
    {
        $this->enableOperations();
        $this->app->instance(OperationalReadinessRepository::class, new class implements OperationalReadinessRepository
        {
            public function isMysqlAvailable(): bool
            {
                throw new LogicException('Liveness must not invoke readiness.');
            }
        });

        $this->withToken('operations-test-token')->get('/internal/health/live')
            ->assertOk()
            ->assertExactJson(['status' => 'alive']);
    }

    public function test_readiness_reports_only_mysql_and_hides_database_failure_details(): void
    {
        $this->enableOperations();
        $this->app->instance(OperationalReadinessRepository::class, new class implements OperationalReadinessRepository
        {
            public function isMysqlAvailable(): bool
            {
                return false;
            }
        });

        $this->withToken('operations-test-token')->get('/internal/health/ready')
            ->assertStatus(503)
            ->assertExactJson([
                'status' => 'not_ready',
                'checks' => ['mysql' => 'down'],
            ])
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('localhost');
    }

    public function test_metrics_follow_prometheus_contract_and_include_zero_series_without_sensitive_labels(): void
    {
        $token = 'operations-test-token';
        $this->enableOperations($token);

        $first = $this->withToken($token)->get('/internal/metrics')
            ->assertOk()
            ->assertHeader('Content-Type', PrometheusOperationalMetricsRenderer::ContentType);
        $second = $this->withToken($token)->get('/internal/metrics')->assertOk();
        $body = $first->getContent();

        self::assertSame($body, $second->getContent());
        self::assertStringEndsWith("\n", $body);
        self::assertStringContainsString('# HELP eventrelay_build_info ', $body);
        self::assertStringContainsString('# TYPE eventrelay_build_info gauge', $body);
        self::assertStringContainsString('eventrelay_build_info{transport="redis"} 1', $body);
        foreach (['pending', 'processing', 'retry_scheduled', 'succeeded', 'failed'] as $status) {
            self::assertStringContainsString("eventrelay_deliveries{status=\"{$status}\"} 0", $body);
        }
        foreach (['pending', 'publishing', 'published'] as $status) {
            self::assertStringContainsString("eventrelay_outbox_messages{status=\"{$status}\"} 0", $body);
        }
        foreach (['delivery_id', 'event_id', 'endpoint_id', 'event_type', 'target', 'error', 'secret', 'token', 'claim_token'] as $forbidden) {
            self::assertStringNotContainsString($forbidden.'=', $body);
        }
        self::assertStringNotContainsString($token, $body);
    }

    public function test_metrics_returns_generic_503_when_durable_data_is_unavailable(): void
    {
        $this->enableOperations();
        $this->app->instance(OperationalSnapshotRepository::class, new class implements OperationalSnapshotRepository
        {
            public function collect(\DateTimeImmutable $now): OperationalSnapshot
            {
                throw new OperationalDataUnavailable(new PDOException('SQLSTATE[HY000] mysql.internal secret'));
            }
        });

        $this->withToken('operations-test-token')->get('/internal/metrics')
            ->assertStatus(503)
            ->assertSee('Service unavailable.')
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('mysql.internal')
            ->assertDontSee('secret');
    }

    private function enableOperations(string $token = 'operations-test-token'): void
    {
        config([
            'operations.enabled' => true,
            'operations.bearer_token' => $token,
        ]);
        $this->app->forgetInstance(OperationsEndpointConfiguration::class);
    }
}

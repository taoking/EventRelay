<?php

declare(strict_types=1);

namespace Runtime;

final class RuntimeContext
{
    private readonly Eventually $eventually;

    private readonly HttpProbe $http;

    /** @var array<string, list<string>> */
    private readonly array $defaultProjectSnapshot;

    /** @var array<string, scalar> */
    private array $observations = [];

    public function __construct(
        public readonly ScenarioIdentity $identity,
        public readonly DockerRuntime $docker,
        public readonly ProcessManager $processes,
        public readonly Cancellation $cancellation,
        public readonly Redactor $redactor,
        private readonly string $operationsToken,
    ) {
        $this->eventually = new Eventually;
        $this->defaultProjectSnapshot = $this->docker->defaultProjectSnapshot();
    }

    public function boot(string $transport): void
    {
        $this->docker->up();
        foreach (['mysql', 'redis', 'rabbitmq'] as $service) {
            $this->docker->waitForServiceHealth($service, $this->eventually);
        }
        $this->docker->waitForMySqlConnection($this->eventually);

        $baseUrl = $this->docker->dynamicHttpBaseUrl();
        $this->http = new HttpProbe($baseUrl);
        $this->eventually->until(
            fn (): bool => $this->statusOrUnavailable('/internal/health/live') === 200,
            Deadline::afterSeconds(120),
            'operations liveness HTTP 200',
            $this->cancellation,
        );
        $this->docker->artisan(['migrate:fresh', '--force'], 'fresh runtime migration', 120.0);
        $this->eventually->until(
            fn (): bool => $this->statusOrUnavailable('/internal/health/ready') === 200,
            Deadline::afterSeconds(60),
            'operations readiness HTTP 200 after migration',
            $this->cancellation,
        );
        $this->observe('transport', $transport);
        $this->observe('app_http', $baseUrl);
    }

    public function request(string $method, string $path, ?array $json = null): HttpResponse
    {
        $headers = str_starts_with($path, '/internal/')
            ? ['Authorization' => 'Bearer '.$this->operationsToken]
            : [];

        return $this->http->request($method, $path, $json, $headers);
    }

    public function eventually(): Eventually
    {
        return $this->eventually;
    }

    public function createPendingDelivery(string $suffix): string
    {
        $eventType = 'runtime.'.$suffix;
        $endpoint = $this->request('POST', '/api/endpoints', [
            'name' => 'Runtime '.$suffix,
            'url' => 'http://127.0.0.1/runtime-'.$suffix,
        ]);
        if ($endpoint->status !== 201) {
            throw new RuntimeException('Unable to create runtime Endpoint.');
        }
        $endpointId = $endpoint->json()['data']['id'] ?? null;
        if (! is_string($endpointId)) {
            throw new RuntimeException('Runtime Endpoint response has no public ID.');
        }
        if ($this->request('PUT', '/api/endpoints/'.$endpointId.'/subscriptions', ['types' => [$eventType]])->status !== 200) {
            throw new RuntimeException('Unable to subscribe runtime Endpoint.');
        }
        if ($this->request('POST', '/api/events', ['type' => $eventType, 'payload' => (object) []])->status !== 201) {
            throw new RuntimeException('Unable to create runtime Event.');
        }

        $deliveries = $this->request('GET', '/api/deliveries')->json()['data'] ?? null;
        if (! is_array($deliveries) || $deliveries === []) {
            throw new RuntimeException('Runtime delivery list is unexpectedly empty.');
        }
        $delivery = end($deliveries);
        $deliveryId = is_array($delivery) ? ($delivery['id'] ?? null) : null;
        if (! is_string($deliveryId)) {
            throw new RuntimeException('Runtime Delivery response has no public ID.');
        }

        return $deliveryId;
    }

    public function deliveryStatus(string $deliveryId): string
    {
        $status = $this->request('GET', '/api/deliveries/'.$deliveryId)->json()['data']['status'] ?? null;
        if (! is_string($status)) {
            throw new RuntimeException('Runtime Delivery status is missing.');
        }

        return $status;
    }

    /** @return list<array{status: string, publication_attempts: int}> */
    public function outboxRows(): array
    {
        $output = $this->docker->artisan([
            'tinker',
            "--execute=echo json_encode(Illuminate\\Support\\Facades\\DB::table('delivery_outbox_messages')->orderBy('id')->get(['status','publication_attempts'])->map(fn (\$row) => ['status' => \$row->status, 'publication_attempts' => (int) \$row->publication_attempts])->all());",
        ], 'read runtime outbox state');
        $decoded = json_decode(trim($output), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Unable to decode runtime Outbox state.');
        }

        return $decoded;
    }

    public function rabbitConsumerCount(): int
    {
        $output = trim($this->docker->serviceCommand('rabbitmq', ['rabbitmqctl', 'list_consumers', '-q'], 'read RabbitMQ consumer count'));

        return count(array_filter(
            preg_split('/\R/', $output) ?: [],
            static fn (string $line): bool => $line !== '' && ! str_starts_with($line, 'queue_name'),
        ));
    }

    public function rabbitQueueEmpty(): bool
    {
        $output = trim($this->docker->serviceCommand('rabbitmq', ['rabbitmqctl', 'list_queues', 'name', 'messages', '-q'], 'read RabbitMQ queue state'));

        return preg_match('/\s0$/m', $output) === 1;
    }

    /** @param scalar $value */
    public function observe(string $key, string|int|float|bool $value): void
    {
        $this->observations[$key] = $value;
    }

    /** @return array<string, scalar> */
    public function observations(): array
    {
        return $this->observations;
    }

    public function cleanup(): void
    {
        $this->processes->terminateAll();
        $this->docker->down();
        if ($this->docker->ownedResourceIds() !== []) {
            throw new RuntimeException('Owned runtime Docker resources remain after cleanup.');
        }
        if ($this->docker->defaultProjectSnapshot() !== $this->defaultProjectSnapshot) {
            throw new RuntimeException('The default eventrelay Docker Compose project changed during runtime validation.');
        }
    }

    private function statusOrUnavailable(string $path): int
    {
        try {
            return $this->request('GET', $path)->status;
        } catch (RuntimeException) {
            return 0;
        }
    }
}

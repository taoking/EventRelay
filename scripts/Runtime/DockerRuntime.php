<?php

declare(strict_types=1);

namespace Runtime;

final class DockerRuntime
{
    private readonly OwnershipGuard $guard;

    private bool $cleaningUp = false;

    /** @param array<string, string> $environment */
    public function __construct(
        private readonly string $repositoryRoot,
        private readonly string $composeFile,
        private readonly ScenarioIdentity $identity,
        private readonly ProcessManager $processes,
        private readonly array $environment,
        private readonly ?Cancellation $cancellation = null,
    ) {
        $this->guard = new OwnershipGuard;
        $this->guard->assertProject($this->identity);
    }

    public function up(): void
    {
        $this->assertExistingProjectResourcesOwned();
        $this->compose(['up', '--detach', '--build', '--remove-orphans'], 'runtime compose up', 240.0);
    }

    public function down(): void
    {
        $this->guard->assertProject($this->identity);
        $this->cleaningUp = true;
        $this->assertExistingProjectResourcesOwned();
        $this->compose(['down', '--volumes', '--remove-orphans'], 'runtime compose down', 90.0);
    }

    public function stopService(string $service): void
    {
        $this->assertOwnedService($service);
        $this->compose(['stop', $service], 'runtime compose stop '.$service, 60.0);
    }

    public function startService(string $service): void
    {
        $this->assertOwnedService($service);
        $this->compose(['start', $service], 'runtime compose start '.$service, 60.0);
    }

    public function waitForServiceHealth(string $service, Eventually $eventually, float $seconds = 90.0): void
    {
        $this->assertOwnedService($service);
        $eventually->until(function () use ($service): bool {
            $id = $this->serviceId($service);
            if ($id === null) {
                return false;
            }

            return trim($this->docker(['inspect', '--format', '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}', $id], 'inspect '.$service, 15.0)) === 'healthy';
        }, Deadline::afterSeconds($seconds), $service.' health', $this->cancellation);
    }

    public function waitForServiceStopped(string $service, Eventually $eventually, float $seconds = 30.0): void
    {
        $this->assertOwnedService($service);
        $eventually->until(function () use ($service): bool {
            $id = $this->serviceId($service);
            if ($id === null) {
                return true;
            }

            return trim($this->docker(['inspect', '--format', '{{.State.Running}}', $id], 'inspect '.$service, 15.0)) === 'false';
        }, Deadline::afterSeconds($seconds), $service.' stopped', $this->cancellation);
    }

    public function waitForServiceRunning(string $service, Eventually $eventually, float $seconds = 30.0): void
    {
        $this->assertOwnedService($service);
        $eventually->until(function () use ($service): bool {
            $id = $this->serviceId($service);
            if ($id === null) {
                return false;
            }

            return trim($this->docker(['inspect', '--format', '{{.State.Running}}', $id], 'inspect '.$service, 15.0)) === 'true';
        }, Deadline::afterSeconds($seconds), $service.' running', $this->cancellation);
    }

    public function dynamicHttpBaseUrl(): string
    {
        $this->assertOwnedService('app');
        $mapping = trim($this->compose(['port', 'app', '8000'], 'discover app port', 15.0));
        if (! preg_match('/(?::|\])(\d+)$/', $mapping, $matches)) {
            throw new RuntimeException('Unable to parse the dynamic app HTTP port.');
        }

        return 'http://127.0.0.1:'.$matches[1];
    }

    public function serviceContainerId(string $service): string
    {
        $this->assertOwnedService($service);

        return $this->serviceId($service) ?? throw new RuntimeException('Owned runtime service '.$service.' is missing.');
    }

    /** @param list<string> $artisanArguments */
    public function artisan(array $artisanArguments, string $description, float $timeoutSeconds = 60.0): string
    {
        $this->assertOwnedService('app');

        return $this->compose(['exec', '-T', 'app', 'php', 'artisan', ...$artisanArguments], $description, $timeoutSeconds);
    }

    /** @param list<string> $arguments */
    public function serviceCommand(string $service, array $arguments, string $description, float $timeoutSeconds = 60.0): string
    {
        $this->assertOwnedService($service);

        return $this->compose(['exec', '-T', $service, ...$arguments], $description, $timeoutSeconds);
    }

    /** @param list<string> $artisanArguments */
    public function startArtisan(array $artisanArguments, string $description): ManagedProcess
    {
        $this->assertOwnedService('app');

        return $this->processes->start(
            $this->composeCommand([
                'exec',
                '-T',
                'app',
                'sh',
                '-lc',
                'printf "__EVENTRELAY_RUNTIME_CHILD_PID=%s\\n" "$$"; exec php artisan "$@"',
                'runtime-artisan',
                ...$artisanArguments,
            ]),
            $this->repositoryRoot,
            $this->environment,
            $description,
        );
    }

    public function terminateArtisan(ManagedProcess $process, Eventually $eventually): int
    {
        $pid = $eventually->until(function () use ($process): int|false {
            if (preg_match('/__EVENTRELAY_RUNTIME_CHILD_PID=(\d+)/', $process->stdoutTail(), $matches) !== 1) {
                return false;
            }

            return (int) $matches[1];
        }, Deadline::afterSeconds(15), 'owned Artisan child PID', $this->cancellation);
        if ($pid < 2) {
            throw new RuntimeException('Runtime Artisan child reported an unsafe PID.');
        }

        $this->serviceCommand('app', ['/bin/kill', '-TERM', (string) $pid], 'TERM owned Artisan child');

        return $process->wait(Deadline::afterSeconds(10), $this->cancellation);
    }

    /** @return list<string> */
    public function ownedResourceIds(): array
    {
        $ids = [];
        foreach ([
            ['ps', '-aq'],
            ['network', 'ls', '-q'],
            ['volume', 'ls', '-q'],
        ] as $command) {
            $output = $this->docker([
                ...$command,
                '--filter', 'label=com.eventrelay.runtime=true',
                '--filter', 'label=com.eventrelay.runtime.run-id='.$this->identity->runId,
            ], 'list owned resources', 15.0);
            foreach (preg_split('/\R/', trim($output)) ?: [] as $id) {
                if ($id !== '') {
                    $ids[] = $id;
                }
            }
        }

        sort($ids);

        return array_values(array_unique($ids));
    }

    /** @return array<string, list<string>> */
    public function defaultProjectSnapshot(): array
    {
        $snapshot = [];
        foreach ([
            'containers' => ['ps', '-aq'],
            'networks' => ['network', 'ls', '-q'],
            'volumes' => ['volume', 'ls', '-q'],
        ] as $name => $command) {
            $output = $this->docker([...$command, '--filter', 'label=com.docker.compose.project=eventrelay'], 'snapshot default project', 15.0);
            $values = array_values(array_filter(preg_split('/\R/', trim($output)) ?: [], static fn (string $value): bool => $value !== ''));
            sort($values);
            $snapshot[$name] = $values;
        }

        return $snapshot;
    }

    public function diagnostics(): string
    {
        $parts = [];
        $wasCleaningUp = $this->cleaningUp;
        $this->cleaningUp = true;
        try {
            $parts[] = 'compose ps:'.PHP_EOL.$this->compose(['ps', '--all'], 'runtime compose ps', 15.0);
            foreach (['app', 'mysql', 'redis', 'rabbitmq'] as $service) {
                $parts[] = $service.' logs:'.PHP_EOL.$this->compose(['logs', '--tail', '40', $service], 'runtime logs '.$service, 15.0);
            }
        } catch (\Throwable $exception) {
            $parts[] = 'diagnostics error: '.$exception->getMessage();
        } finally {
            $this->cleaningUp = $wasCleaningUp;
        }

        return implode(PHP_EOL, $parts);
    }

    /** @return array<string, string> */
    public function labels(string $id): array
    {
        $encoded = $this->docker(['inspect', $id], 'inspect labels', 15.0);
        $resources = json_decode($encoded, true);
        $resource = is_array($resources) ? ($resources[0] ?? null) : null;
        $labels = is_array($resource)
            ? ($resource['Config']['Labels'] ?? $resource['Labels'] ?? null)
            : null;
        if (! is_array($labels)) {
            throw new RuntimeException('Unable to read Docker resource labels.');
        }

        return array_filter($labels, 'is_string');
    }

    private function assertOwnedService(string $service): void
    {
        $this->guard->assertService($service);
        $id = $this->serviceId($service);
        if ($id === null) {
            throw new RuntimeException('Owned runtime service '.$service.' is missing.');
        }
        $this->guard->assertLabels($this->labels($id), $this->identity);
    }

    private function assertExistingProjectResourcesOwned(): void
    {
        $this->guard->assertProject($this->identity);
        $output = $this->docker(['ps', '-aq', '--filter', 'label=com.docker.compose.project='.$this->identity->project], 'list project containers', 15.0);
        foreach (array_filter(preg_split('/\R/', trim($output)) ?: []) as $id) {
            $this->guard->assertLabels($this->labels($id), $this->identity);
        }

        foreach ([['network', 'ls', '-q'], ['volume', 'ls', '-q']] as $command) {
            $resources = $this->docker([...$command, '--filter', 'label=com.docker.compose.project='.$this->identity->project], 'list project resources', 15.0);
            foreach (array_filter(preg_split('/\R/', trim($resources)) ?: []) as $id) {
                $this->guard->assertLabels($this->labels($id), $this->identity);
            }
        }
    }

    private function serviceId(string $service): ?string
    {
        $output = trim($this->compose(['ps', '--all', '-q', $service], 'resolve '.$service, 15.0));

        return $output === '' ? null : $output;
    }

    /** @param list<string> $arguments */
    private function compose(array $arguments, string $description, float $timeoutSeconds): string
    {
        $process = $this->processes->run(
            $this->composeCommand($arguments),
            $this->repositoryRoot,
            $this->environment,
            $description,
            $timeoutSeconds,
            $this->activeCancellation(),
        );

        return $process->stdoutTail();
    }

    /** @param list<string> $arguments
     * @return list<string>
     */
    private function composeCommand(array $arguments): array
    {
        return ['docker', 'compose', '--project-name', $this->identity->project, '--file', $this->composeFile, ...$arguments];
    }

    /** @param list<string> $arguments */
    private function docker(array $arguments, string $description, float $timeoutSeconds): string
    {
        $process = $this->processes->run(
            ['docker', ...$arguments],
            $this->repositoryRoot,
            $this->environment,
            $description,
            $timeoutSeconds,
            $this->activeCancellation(),
        );

        return $process->stdoutTail();
    }

    private function activeCancellation(): ?Cancellation
    {
        return $this->cleaningUp ? null : $this->cancellation;
    }
}

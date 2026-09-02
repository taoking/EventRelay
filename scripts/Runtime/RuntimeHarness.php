<?php

declare(strict_types=1);

namespace Runtime;

final class RuntimeHarness
{
    /** @var array<string, RuntimeScenario> */
    private array $scenarios;

    private readonly Cancellation $cancellation;

    public function __construct(
        private readonly string $repositoryRoot,
        private readonly RunIdentity $runIdentity,
    ) {
        $this->scenarios = [];
        foreach ([
            new IsolationSmokeScenario,
            new MySqlOutageScenario,
            new RabbitConsumerScenario,
            new OutboxWorkerScenario,
        ] as $scenario) {
            $this->scenarios[$scenario->name()] = $scenario;
        }
        $this->cancellation = new Cancellation;
        $this->registerSignalHandlers();
    }

    /** @return list<string> */
    public function scenarioNames(): array
    {
        return array_keys($this->scenarios);
    }

    /** @return array<string, array<string, scalar>> */
    public function runSuite(): array
    {
        $reports = [];
        foreach ($this->scenarios as $scenario) {
            $reports[$scenario->name()] = $this->run($scenario->name());
        }

        return $reports;
    }

    /** @return array<string, scalar> */
    public function run(string $name): array
    {
        $scenario = $this->scenarios[$name] ?? null;
        if (! $scenario instanceof RuntimeScenario) {
            throw new RuntimeException('Unknown runtime scenario: '.$name.'. Use list to view supported scenarios.');
        }
        if ($this->cancellation->requested()) {
            throw new RuntimeCancelled('Runtime harness received a termination signal before starting '.$name.'.');
        }

        $identity = $this->runIdentity->scenario($scenario->name());
        $token = bin2hex(random_bytes(24));
        $redactor = new Redactor([$token, 'runtime-root-credential', 'runtime-db-credential', 'runtime-rabbit-credential']);
        $processes = new ProcessManager;
        $docker = new DockerRuntime(
            $this->repositoryRoot,
            $this->repositoryRoot.'/docker-compose.runtime.yml',
            $identity,
            $processes,
            $this->environment($identity, $token, $scenario->transport()),
            $this->cancellation,
        );
        $context = new RuntimeContext($identity, $docker, $processes, $this->cancellation, $redactor, $token);
        $failure = null;

        fwrite(STDOUT, sprintf("runtime scenario=%s project=%s phase=start\n", $name, $identity->project));
        try {
            $context->boot($scenario->transport());
            $scenario->run($context);
        } catch (\Throwable $exception) {
            $failure = $exception;
            fwrite(STDERR, $redactor->redact($this->failureDiagnostics($name, $docker, $processes, $exception)).PHP_EOL);
        }

        try {
            $context->cleanup();
        } catch (\Throwable $cleanupException) {
            $failure ??= $cleanupException;
            fwrite(STDERR, $redactor->redact('Runtime cleanup failure: '.$cleanupException->getMessage()).PHP_EOL);
        }

        if ($failure !== null) {
            throw $failure;
        }

        $observations = $context->observations();
        $observations['project'] = $identity->project;
        $observations['cleanup'] = 'zero-residue';
        fwrite(STDOUT, sprintf(
            "runtime scenario=%s phase=PASS observations=%s\n",
            $name,
            json_encode($observations, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ));

        return $observations;
    }

    public function cleanupCurrent(): void
    {
        foreach ($this->scenarioNames() as $name) {
            $identity = $this->runIdentity->scenario($name);
            $processes = new ProcessManager;
            $docker = new DockerRuntime(
                $this->repositoryRoot,
                $this->repositoryRoot.'/docker-compose.runtime.yml',
                $identity,
                $processes,
                $this->environment($identity, 'cleanup-token', 'redis'),
                $this->cancellation,
            );
            $docker->down();
            if ($docker->ownedResourceIds() !== []) {
                throw new RuntimeException('Owned runtime Docker resources remain after cleanup-current.');
            }
        }
    }

    /** @return array<string, string> */
    private function environment(ScenarioIdentity $identity, string $token, string $transport): array
    {
        $environment = [];
        foreach (['PATH', 'HOME', 'DOCKER_HOST', 'DOCKER_CONTEXT', 'XDG_RUNTIME_DIR', 'TMPDIR', 'USER', 'COMPOSER_HOME', 'LANG', 'LC_ALL'] as $name) {
            $value = getenv($name);
            if (is_string($value) && $value !== '') {
                $environment[$name] = $value;
            }
        }
        $environment['EVENTRELAY_RUNTIME_RUN_ID'] = $identity->runId;
        $environment['EVENTRELAY_RUNTIME_OPERATIONS_BEARER_TOKEN'] = $token;
        $environment['EVENTRELAY_RUNTIME_DELIVERY_TRANSPORT'] = $transport;

        return $environment;
    }

    private function registerSignalHandlers(): void
    {
        if (! extension_loaded('pcntl')) {
            return;
        }
        pcntl_async_signals(true);
        pcntl_signal(SIGINT, function (): void {
            $this->cancellation->request();
        });
        pcntl_signal(SIGTERM, function (): void {
            $this->cancellation->request();
        });
    }

    private function failureDiagnostics(string $name, DockerRuntime $docker, ProcessManager $processes, \Throwable $exception): string
    {
        return sprintf(
            "Runtime scenario failure\nscenario=%s\nexception=%s\nmessage=%s\nmanaged processes:\n%s\n%s",
            $name,
            $exception::class,
            $exception->getMessage(),
            $processes->diagnostics(),
            $docker->diagnostics(),
        );
    }
}

<?php

declare(strict_types=1);

namespace Runtime;

use Symfony\Component\Process\Process;

final class ManagedProcess
{
    private readonly Process $process;

    private readonly TailBuffer $stdout;

    private readonly TailBuffer $stderr;

    /** @param list<string> $argv
     * @param  array<string, string>  $environment
     */
    public function __construct(
        array $argv,
        string $workingDirectory,
        array $environment,
        private readonly string $description,
    ) {
        if ($argv === [] || $argv[0] === '') {
            throw new RuntimeException('Managed process requires a non-empty argv array.');
        }

        $this->process = new Process($argv, $workingDirectory, $environment);
        $this->process->setTimeout(null);
        $this->process->setIdleTimeout(null);
        $this->stdout = new TailBuffer;
        $this->stderr = new TailBuffer;
    }

    public function start(): void
    {
        $this->process->start(function (string $type, string $buffer): void {
            if ($type === Process::OUT) {
                $this->stdout->append($buffer);

                return;
            }

            $this->stderr->append($buffer);
        });
    }

    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    public function pid(): ?int
    {
        $pid = $this->process->getPid();

        return is_int($pid) ? $pid : null;
    }

    public function exitCode(): ?int
    {
        return $this->process->getExitCode();
    }

    public function stdoutTail(): string
    {
        return $this->stdout->value();
    }

    public function description(): string
    {
        return $this->description;
    }

    public function stderrTail(): string
    {
        return $this->stderr->value();
    }

    public function wait(Deadline $deadline, ?Cancellation $cancellation = null): int
    {
        while ($this->isRunning()) {
            if ($cancellation?->requested()) {
                $this->terminate();
                throw new RuntimeCancelled('Runtime harness cancellation requested while running '.$this->description.'.');
            }

            if ($deadline->expired()) {
                $this->terminate();
                throw new RuntimeException('Timed out while running '.$this->description.'.');
            }

            usleep(min(50_000, max(1_000, $deadline->remainingMicroseconds())));
        }

        return $this->process->getExitCode() ?? 1;
    }

    public function terminate(float $graceSeconds = 3.0): void
    {
        if (! $this->isRunning()) {
            return;
        }

        $this->process->signal(SIGTERM);
        $deadline = Deadline::afterSeconds($graceSeconds);
        while ($this->isRunning() && ! $deadline->expired()) {
            usleep(min(50_000, max(1_000, $deadline->remainingMicroseconds())));
        }

        if ($this->isRunning()) {
            $this->process->signal(SIGKILL);
        }

        $this->process->wait();
    }
}

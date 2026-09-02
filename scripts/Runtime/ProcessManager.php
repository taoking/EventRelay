<?php

declare(strict_types=1);

namespace Runtime;

final class ProcessManager
{
    /** @var list<ManagedProcess> */
    private array $processes = [];

    /** @param list<string> $argv
     * @param  array<string, string>  $environment
     */
    public function start(array $argv, string $workingDirectory, array $environment, string $description): ManagedProcess
    {
        $process = new ManagedProcess($argv, $workingDirectory, $environment, $description);
        $process->start();
        $this->processes[] = $process;

        return $process;
    }

    /** @param list<string> $argv
     * @param  array<string, string>  $environment
     */
    public function run(array $argv, string $workingDirectory, array $environment, string $description, float $timeoutSeconds, ?Cancellation $cancellation = null): ManagedProcess
    {
        $process = $this->start($argv, $workingDirectory, $environment, $description);
        $exitCode = $process->wait(Deadline::afterSeconds($timeoutSeconds), $cancellation);
        if ($exitCode !== 0) {
            throw new RuntimeException(sprintf('%s exited with %d. stderr tail: %s', $description, $exitCode, $process->stderrTail()));
        }

        return $process;
    }

    public function terminateAll(): void
    {
        foreach ($this->processes as $process) {
            $process->terminate();
        }
    }

    public function runningCount(): int
    {
        return count(array_filter($this->processes, static fn (ManagedProcess $process): bool => $process->isRunning()));
    }

    public function diagnostics(): string
    {
        $diagnostics = [];
        foreach ($this->processes as $process) {
            $diagnostics[] = sprintf(
                "process=%s\nexit=%s\nstdout tail:\n%s\nstderr tail:\n%s",
                $process->description(),
                $process->exitCode() === null ? 'running' : (string) $process->exitCode(),
                $process->stdoutTail(),
                $process->stderrTail(),
            );
        }

        return implode(PHP_EOL, $diagnostics);
    }
}

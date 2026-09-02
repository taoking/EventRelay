<?php

declare(strict_types=1);

namespace Runtime;

use Closure;

final readonly class Eventually
{
    public function __construct(
        private int $pollIntervalMicroseconds = 100_000,
    ) {
        if ($this->pollIntervalMicroseconds < 1_000 || $this->pollIntervalMicroseconds > 1_000_000) {
            throw new RuntimeException('Eventually poll interval must be between 1ms and 1s.');
        }
    }

    /** @param Closure(): mixed $condition */
    public function until(Closure $condition, Deadline $deadline, string $description, ?Cancellation $cancellation = null): mixed
    {
        $lastObservation = 'no observation';

        do {
            if ($cancellation?->requested()) {
                throw new RuntimeCancelled('Runtime harness cancellation requested while waiting for '.$description.'.');
            }

            $observation = $condition();
            if ($observation !== false && $observation !== null) {
                return $observation;
            }

            $lastObservation = $this->describe($observation);
            if ($deadline->expired()) {
                throw new RuntimeException(sprintf('Timed out waiting for %s. Last observation: %s', $description, $lastObservation));
            }

            usleep(min($this->pollIntervalMicroseconds, max(1_000, $deadline->remainingMicroseconds())));
        } while (true);
    }

    /** @param Closure(): bool $condition */
    public function during(Closure $condition, Deadline $deadline, string $description, ?Cancellation $cancellation = null): void
    {
        do {
            if ($cancellation?->requested()) {
                throw new RuntimeCancelled('Runtime harness cancellation requested while observing '.$description.'.');
            }

            if (! $condition()) {
                throw new RuntimeException('Condition became false while observing '.$description.'.');
            }

            if ($deadline->expired()) {
                return;
            }

            usleep(min($this->pollIntervalMicroseconds, max(1_000, $deadline->remainingMicroseconds())));
        } while (true);
    }

    private function describe(mixed $observation): string
    {
        $encoded = json_encode($observation, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return substr($encoded === false ? get_debug_type($observation) : $encoded, 0, 512);
    }
}

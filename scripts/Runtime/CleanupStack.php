<?php

declare(strict_types=1);

namespace Runtime;

use Closure;

final class CleanupStack
{
    /** @var list<Closure(): void> */
    private array $callbacks = [];

    public function defer(Closure $callback): void
    {
        $this->callbacks[] = $callback;
    }

    /** @return list<string> */
    public function run(): array
    {
        $errors = [];
        while (($callback = array_pop($this->callbacks)) !== null) {
            try {
                $callback();
            } catch (\Throwable $exception) {
                $errors[] = $exception::class.': '.$exception->getMessage();
            }
        }

        return $errors;
    }
}

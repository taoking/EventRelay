<?php

declare(strict_types=1);

namespace Runtime;

final class TailBuffer
{
    private string $value = '';

    public function __construct(private readonly int $maximumBytes = 16_384)
    {
        if ($this->maximumBytes < 128) {
            throw new RuntimeException('Tail buffer must retain at least 128 bytes.');
        }
    }

    public function append(string $chunk): void
    {
        $this->value .= $chunk;
        if (strlen($this->value) > $this->maximumBytes) {
            $this->value = substr($this->value, -$this->maximumBytes);
        }
    }

    public function value(): string
    {
        return $this->value;
    }
}

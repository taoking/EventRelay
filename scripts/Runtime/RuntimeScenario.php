<?php

declare(strict_types=1);

namespace Runtime;

interface RuntimeScenario
{
    public function name(): string;

    public function transport(): string;

    public function run(RuntimeContext $context): void;
}

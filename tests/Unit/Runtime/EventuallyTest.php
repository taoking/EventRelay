<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Runtime\Cancellation;
use Runtime\Deadline;
use Runtime\Eventually;
use Runtime\RuntimeCancelled;

final class EventuallyTest extends TestCase
{
    public function test_requested_cancellation_interrupts_a_bounded_wait(): void
    {
        $cancellation = new Cancellation;
        $cancellation->request();

        $this->expectException(RuntimeCancelled::class);
        (new Eventually)->until(static fn (): bool => false, Deadline::afterSeconds(1), 'controlled wait', $cancellation);
    }
}

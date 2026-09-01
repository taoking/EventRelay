<?php

declare(strict_types=1);

namespace App\Application\Operations;

final readonly class OperationalSnapshot
{
    /**
     * @param  array<string, int>  $deliveryCounts
     * @param  array<string, int>  $outboxCounts
     */
    public function __construct(
        public array $deliveryCounts,
        public array $outboxCounts,
        public int $outboxDuePending,
        public int $outboxOldestDueAgeSeconds,
        public int $deliveryRetriesDue,
        public int $deliveryStaleProcessingCandidates,
    ) {}

    public function deadLetters(): int
    {
        return $this->deliveryCounts['failed'];
    }
}

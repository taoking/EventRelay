<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations;

use App\Application\Operations\OperationalMetricsRenderer;
use App\Application\Operations\OperationalSnapshot;
use App\Domain\Delivery\DeliveryStatus;
use App\Infrastructure\Persistence\Eloquent\DeliveryOutboxMessageStatus;
use LogicException;

final class PrometheusOperationalMetricsRenderer implements OperationalMetricsRenderer
{
    public const string ContentType = 'text/plain; version=0.0.4; charset=utf-8';

    public function contentType(): string
    {
        return self::ContentType;
    }

    public function render(OperationalSnapshot $snapshot, string $transport): string
    {
        if (! in_array($transport, ['redis', 'rabbitmq'], true)) {
            throw new LogicException('Delivery transport metrics label is invalid.');
        }

        $lines = [];
        $this->family($lines, 'eventrelay_build_info', 'Configured EventRelay delivery transport.', 'gauge');
        $lines[] = sprintf('eventrelay_build_info{transport="%s"} 1', $this->label($transport));

        $this->family($lines, 'eventrelay_deliveries', 'Durable deliveries grouped by status.', 'gauge');
        foreach (DeliveryStatus::cases() as $status) {
            $lines[] = sprintf(
                'eventrelay_deliveries{status="%s"} %d',
                $this->label($status->value),
                $this->count($snapshot->deliveryCounts, $status->value),
            );
        }

        $this->family($lines, 'eventrelay_outbox_messages', 'Durable delivery outbox messages grouped by status.', 'gauge');
        foreach (DeliveryOutboxMessageStatus::cases() as $status) {
            $lines[] = sprintf(
                'eventrelay_outbox_messages{status="%s"} %d',
                $this->label($status->value),
                $this->count($snapshot->outboxCounts, $status->value),
            );
        }

        $this->family($lines, 'eventrelay_outbox_due_pending', 'Durable outbox intents currently eligible for publication.', 'gauge');
        $lines[] = 'eventrelay_outbox_due_pending '.$snapshot->outboxDuePending;
        $this->family($lines, 'eventrelay_outbox_oldest_due_age_seconds', 'Age in seconds of the oldest durable outbox intent eligible for publication.', 'gauge');
        $lines[] = 'eventrelay_outbox_oldest_due_age_seconds '.$snapshot->outboxOldestDueAgeSeconds;
        $this->family($lines, 'eventrelay_delivery_retries_due', 'Durable deliveries whose retry is currently due.', 'gauge');
        $lines[] = 'eventrelay_delivery_retries_due '.$snapshot->deliveryRetriesDue;
        $this->family($lines, 'eventrelay_delivery_stale_processing_candidates', 'Durable processing deliveries currently eligible for stale recovery.', 'gauge');
        $lines[] = 'eventrelay_delivery_stale_processing_candidates '.$snapshot->deliveryStaleProcessingCandidates;
        $this->family($lines, 'eventrelay_dead_letters', 'Durable failed deliveries.', 'gauge');
        $lines[] = 'eventrelay_dead_letters '.$snapshot->deadLetters();

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  list<string>  $lines
     */
    private function family(array &$lines, string $name, string $help, string $type): void
    {
        $lines[] = "# HELP {$name} {$help}";
        $lines[] = "# TYPE {$name} {$type}";
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function count(array $counts, string $status): int
    {
        if (! array_key_exists($status, $counts) || $counts[$status] < 0) {
            throw new LogicException('Operational metric counts must be present and non-negative.');
        }

        return $counts[$status];
    }

    private function label(string $value): string
    {
        return str_replace(['\\', "\n", '"'], ['\\\\', '\\n', '\\"'], $value);
    }
}

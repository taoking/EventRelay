<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Application\Delivery\CreateDelivery;
use App\Application\Delivery\EnqueuePendingDeliveries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PendingDeliveryRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_recovery_reads_pending_deliveries_in_stable_order_and_honors_the_limit(): void
    {
        $first = $this->createPendingDelivery('First recovery endpoint');
        $second = $this->createPendingDelivery('Second recovery endpoint');
        $this->createPendingDelivery('Third recovery endpoint');
        $result = app(EnqueuePendingDeliveries::class)->handle(2);

        self::assertSame(2, $result->ensured);
        self::assertSame([$first, $second], DB::table('delivery_outbox_messages')
            ->join('deliveries', 'delivery_outbox_messages.delivery_id', '=', 'deliveries.id')
            ->orderBy('delivery_outbox_messages.id')
            ->pluck('deliveries.public_id')
            ->all());
    }

    private function createPendingDelivery(string $endpointName): string
    {
        $eventId = (string) $this->postJson('/api/events', [
            'type' => 'order.paid',
            'payload' => (object) ['source' => 'recovery-test'],
        ])->assertCreated()->json('data.id');
        $endpointId = (string) $this->postJson('/api/endpoints', [
            'name' => $endpointName,
            'url' => 'https://example.test/webhooks/recovery',
        ])->assertCreated()->json('data.id');

        return app(CreateDelivery::class)->handle($eventId, $endpointId)->id;
    }
}

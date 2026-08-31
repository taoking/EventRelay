<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_outbox_messages', function (Blueprint $table): void {
            $table->index(['status', 'available_at', 'claimed_until', 'id'], 'delivery_outbox_due_claim_index');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_outbox_messages', function (Blueprint $table): void {
            $table->dropIndex('delivery_outbox_due_claim_index');
        });
    }
};

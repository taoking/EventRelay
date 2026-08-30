<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_outbox_messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('delivery_id')->constrained('deliveries')->restrictOnDelete();
            $table->string('message_type', 64);
            $table->string('dedupe_key', 191)->unique('delivery_outbox_messages_dedupe_key_unique');
            $table->unsignedTinyInteger('attempt_number');
            $table->timestamp('available_at')->nullable();
            $table->string('status', 16)->default('pending');
            $table->uuid('claim_token')->nullable();
            $table->timestamp('claimed_until')->nullable();
            $table->unsignedInteger('publication_attempts')->default(0);
            $table->string('last_error_code', 64)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'claimed_until', 'id'], 'delivery_outbox_claim_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_outbox_messages');
    }
};

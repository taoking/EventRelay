<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_ingress_idempotencies', function (Blueprint $table): void {
            $table->id();
            $table->char('key_digest', 64)->unique('event_ingress_idempotencies_key_digest_unique');
            $table->char('request_fingerprint', 64);
            $table->foreignId('event_id')->constrained('events')->restrictOnDelete();
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_ingress_idempotencies');
    }
};

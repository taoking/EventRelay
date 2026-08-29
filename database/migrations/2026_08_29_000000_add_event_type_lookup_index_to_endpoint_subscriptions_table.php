<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('endpoint_subscriptions', function (Blueprint $table): void {
            $table->index(['event_type', 'endpoint_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('endpoint_subscriptions', function (Blueprint $table): void {
            $table->dropIndex(['event_type', 'endpoint_id']);
        });
    }
};

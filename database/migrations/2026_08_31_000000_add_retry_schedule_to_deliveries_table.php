<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->timestamp('next_attempt_at')->nullable()->after('status');
            $table->index(['status', 'next_attempt_at', 'id'], 'deliveries_due_retry_index');
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->dropIndex('deliveries_due_retry_index');
            $table->dropColumn('next_attempt_at');
        });
    }
};

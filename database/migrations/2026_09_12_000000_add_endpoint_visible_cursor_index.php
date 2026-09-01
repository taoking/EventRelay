<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('endpoints', function (Blueprint $table): void {
            $table->index(['deleted_at', 'id'], 'endpoints_visible_cursor_index');
        });
    }

    public function down(): void
    {
        Schema::table('endpoints', function (Blueprint $table): void {
            $table->dropIndex('endpoints_visible_cursor_index');
        });
    }
};

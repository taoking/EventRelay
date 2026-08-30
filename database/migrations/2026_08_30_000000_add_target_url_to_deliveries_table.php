<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->string('target_url', 2048)->nullable()->after('endpoint_id');
        });

        DB::table('deliveries')
            ->orderBy('deliveries.id')
            ->select(['deliveries.id', 'endpoints.url'])
            ->join('endpoints', 'deliveries.endpoint_id', '=', 'endpoints.id')
            ->each(function (object $delivery): void {
                DB::table('deliveries')
                    ->where('id', $delivery->id)
                    ->update(['target_url' => $delivery->url]);
            });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->dropColumn('target_url');
        });
    }
};

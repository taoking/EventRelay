<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_readiness_probes', function (Blueprint $table): void {
            $table->char('probe_id', 32)->primary();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_readiness_probes');
    }
};

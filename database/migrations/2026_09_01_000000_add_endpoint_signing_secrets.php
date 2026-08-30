<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('endpoint_signing_secrets', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('endpoint_id')->constrained('endpoints')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->text('encrypted_secret');
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
            $table->unique(['endpoint_id', 'version']);
        });

        Schema::table('endpoints', function (Blueprint $table): void {
            $table->foreignId('current_signing_secret_id')->nullable()->after('status')
                ->constrained('endpoint_signing_secrets')->restrictOnDelete();
        });

        Schema::table('deliveries', function (Blueprint $table): void {
            $table->foreignId('signing_secret_id')->nullable()->after('target_url')
                ->constrained('endpoint_signing_secrets')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('signing_secret_id');
        });
        Schema::table('endpoints', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('current_signing_secret_id');
        });
        Schema::dropIfExists('endpoint_signing_secrets');
    }
};

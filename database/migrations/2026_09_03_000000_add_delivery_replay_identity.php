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
            $table->string('creation_key', 72)->default('primary')->after('endpoint_id');
            $table->foreignId('replay_of_delivery_id')->nullable()->after('creation_key')->constrained('deliveries')->restrictOnDelete();
            $table->unique(['event_id', 'endpoint_id', 'creation_key']);
        });

        // MySQL 的 event_id 外键会使用旧复合索引；先建立同样以 event_id 开头的新索引，
        // 再删除旧唯一约束，避免迁移期间破坏外键所需的索引。
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->dropUnique('deliveries_event_id_endpoint_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->unique(['event_id', 'endpoint_id']);
        });
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->dropUnique('deliveries_event_id_endpoint_id_creation_key_unique');
            $table->dropConstrainedForeignId('replay_of_delivery_id');
            $table->dropColumn('creation_key');
        });
    }
};

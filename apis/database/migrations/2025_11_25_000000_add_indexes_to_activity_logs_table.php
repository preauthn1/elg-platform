<?php

declare(strict_types=1);

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
        Schema::table('activity_logs', function (Blueprint $table) {
            // 1. 單欄位 created_at 索引
            $table->index('created_at', 'idx_activity_logs_created_at');

            // 2. causer_id + created_at 複合索引
            $table->index(['causer_id', 'created_at'], 'idx_activity_logs_causer_created_at');

            // 3. subject_id + created_at 複合索引
            $table->index(['subject_id', 'created_at'], 'idx_activity_logs_subject_created_at');

            // 4. log_name + created_at 複合索引
            $table->index(['log_name', 'created_at'], 'idx_activity_logs_log_name_created_at');

            // 5. causer_type + causer_id + created_at 複合索引 (查詢會員或管理員的操作紀錄)
            $table->index(['causer_type', 'causer_id', 'created_at'], 'idx_activity_logs_causer_type_id_created_at');

            // 6. subject_type + subject_id + created_at 複合索引 (查詢訂單或錢包的操作紀錄)
            $table->index(['subject_type', 'subject_id', 'created_at'], 'idx_activity_logs_subject_type_id_created_at');

            // 7. batch_uuid 索引 (批次操作查詢)
            $table->index('batch_uuid', 'idx_activity_logs_batch_uuid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex('idx_activity_logs_created_at');
            $table->dropIndex('idx_activity_logs_causer_created_at');
            $table->dropIndex('idx_activity_logs_subject_created_at');
            $table->dropIndex('idx_activity_logs_log_name_created_at');
            $table->dropIndex('idx_activity_logs_causer_type_id_created_at');
            $table->dropIndex('idx_activity_logs_subject_type_id_created_at');
            $table->dropIndex('idx_activity_logs_batch_uuid');
        });
    }
};

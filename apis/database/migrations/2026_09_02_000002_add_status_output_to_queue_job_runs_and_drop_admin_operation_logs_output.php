<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * queue_job_runs 補執行生命週期（status／output）；admin_operation_logs 的 output 遷入
 *
 * 派發任務時即建列（status=未處理），job 收尾 update 同列為成功／失敗並寫入執行輸出——
 * 一列的生命週期就是一次執行的生命週期，「派發了沒下文」以殘留的未處理列現形。
 *
 * admin_operation_logs.output 廢除：執行輸出統一由 queue_job_runs.output 承載
 * （含 RunAdminOperationJob 的 artisan 輸出與 audit 型稽核輸出），操作紀錄以
 * queue_job_run_id 關聯到自己的執行列。欄位直接 DROP 不搬資料：既有輸出屬
 * 一次性排查資訊，過期即無值，且該表僅後台操作紀錄、量小。
 *
 * 既有 queue_job_runs 列 status 回填為成功（default 1）：它們全是舊制
 * 「執行完成才寫」的列，存在即成功。
 *
 * ⚠️ 判缺契約變更：isCompleted／missingPairs 由「缺列＝缺資料」改為
 * 「無成功列＝缺資料」，查詢端一律過濾 status=成功（同版部署，見 QueueJobRun）。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::table('queue_job_runs', function (Blueprint $table) {
            $table->unsignedTinyInteger('status')->default(1)->after('run_uuid')
                ->comment('執行狀態：0=未處理(已派發) 1=成功 2=失敗（QueueJobRunStatusEnum）');
            // ⚠️ TEXT 不夠裝 --show-list 的完整清單，由 2026_09_03_000001 擴為 MEDIUMTEXT
            // （本檔已上 dev、不可改內容，修正另立 migration）
            $table->text('output')->nullable()->after('source')
                ->comment('執行輸出（人讀摘要；統計細節仍以 Log 為準）');

            // 供 clean:queue-job-runs 保留期清理走索引刪除
            $table->index('created_at', 'idx_created_at');
        });

        SchemaMonitor::table('admin_operation_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('queue_job_run_id')->nullable()->after('status')
                ->comment('本次操作的執行列（queue_job_runs.id）；執行輸出與即時狀態看該列');
            $table->dropColumn('output');
        });
    }

    public function down(): void
    {
        SchemaMonitor::table('queue_job_runs', function (Blueprint $table) {
            $table->dropIndex('idx_created_at');
            $table->dropColumn(['status', 'output']);
        });

        SchemaMonitor::table('admin_operation_logs', function (Blueprint $table) {
            // 忠實還原建表時的型別（2026_07_23 create：mediumText）
            $table->mediumText('output')->nullable()->comment('執行輸出');
            $table->dropColumn('queue_job_run_id');
        });
    }
};

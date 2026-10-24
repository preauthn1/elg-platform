<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * queue_job_runs.output 由 TEXT 擴為 MEDIUMTEXT
 *
 * dev 驗證踩雷：更新遊戲清單固定帶 --show-list，artisan 輸出含完整清單表格
 * （PTSW 666 款 × 兩組別 ≈ 15 萬 bytes），TEXT 上限 65,535 bytes 寫入即爆 1406；
 * QueryException 訊息又內嵌整條 SQL 原文，failed 路徑回寫時二次爆炸。
 *
 * MEDIUMTEXT 對齊被 2026_09_02_000002 廢除的 admin_operation_logs.output 原型別
 * （2026_07_23 建表即 mediumText）——遷移時誤降級為 TEXT 是本次根因。
 * 000002 已上 dev 不可改內容，故另立本檔修正；寫入端另有 1MB 截斷防線
 * （QueueJobRun::finishRun），欄位上限不會再讓收尾寫入本身失敗。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::table('queue_job_runs', function (Blueprint $table) {
            $table->mediumText('output')->nullable()
                ->comment('執行輸出（人讀摘要；統計細節仍以 Log 為準）')
                ->change();
        });
    }

    public function down(): void
    {
        SchemaMonitor::table('queue_job_runs', function (Blueprint $table) {
            $table->text('output')->nullable()
                ->comment('執行輸出（人讀摘要；統計細節仍以 Log 為準）')
                ->change();
        });
    }
};

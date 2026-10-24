<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * 4121 結算引擎的期間游標載體（2026-09-02 code review 修正）。
 *
 * 原游標只看 ggr_settlements 最新列的 period_end：該期無任何代理達階時
 * 不寫列、游標不前進，日結方案累積 400 期後新期永不結算，週期切換也永不
 * promote。改由引擎每期完成時（與 QueueJobRun 完成標記同一交易）推進
 * settled_through，無列的期間也會前進；舊資料以最新列 period_end 兜底。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::table('ggr_plans', function (Blueprint $table) {
            $table->date('settled_through')->nullable()->after('pending_cycle_from')
                ->comment('已結算至此業務日(含,GMT+8);引擎每期完成時推進,無結算列的期間亦前進');
        });
    }

    public function down(): void
    {
        SchemaMonitor::table('ggr_plans', function (Blueprint $table) {
            $table->dropColumn('settled_through');
        });
    }
};

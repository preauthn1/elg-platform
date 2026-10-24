<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * 4121 批次七發放查詢的索引（2026-09-03 code review 修正）。
 *
 * 發放排程每日以 `WHERE payout_status = 0 [AND plan_id = ?] ORDER BY id` 取待發放列，
 * 原本表上只有 general_agent_id / agent_id 索引與 (plan_id, agent_id, period_start)
 * 唯一鍵，查詢只能走 clustered index 全表掃描；PAID 列永久累積，掃描成本逐日成長
 * 而待發放集合恆為當日量級。二級索引尾端自帶主鍵，ORDER BY id 亦免 filesort。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::table('ggr_settlements', function (Blueprint $table) {
            $table->index(['payout_status', 'plan_id'], 'idx_ggr_settlement_payout_plan');
        });
    }

    public function down(): void
    {
        SchemaMonitor::table('ggr_settlements', function (Blueprint $table) {
            $table->dropIndex('idx_ggr_settlement_payout_plan');
        });
    }
};

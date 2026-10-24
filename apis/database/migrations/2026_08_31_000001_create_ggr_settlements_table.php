<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * 推廣代理 GGR 結算結果表 工單4125
 *
 * 一列＝一個方案×代理×結算週期的分潤結果，是 4125 派發紀錄頁的資料源。
 * 本批（批次三）只建表與查詢 API；寫入方（結算引擎）與發放狀態機屬後續批次，
 * 因此欄位是「引擎要落地的契約」：百分比與費用一律存結算當下的快照，
 * 方案之後改設定不回溯影響歷史紀錄。
 *
 * 無審核概念（2026-08-31 使用者定案：連同 4121 的 auto_approve 一併移除），
 * 只有 payout_status 一條發放狀態線。bonus_amount（紅利金額設定）先預留、
 * 前端不顯示，來源後續再定。
 *
 * 僅總代使用，general_agent_id 為可見性軸（同 4121/4123/4124）。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('ggr_settlements', function (Blueprint $table) {
            $table->comment('推廣代理GGR結算結果 工單4125');
            $table->increments('id')->comment('PK流水號');
            $table->unsignedInteger('general_agent_id')->comment('所屬總代agents.id(各總代紀錄不互見,可見性軸)');
            $table->unsignedInteger('plan_id')->comment('來源方案ggr_plans.id');
            $table->unsignedInteger('agent_id')->comment('被結算的代理agents.id');
            $table->tinyInteger('settlement_cycle')->comment('結算週期快照 1:每日 2:每週 3:每月(方案改設定後歷史紀錄仍顯示當時值)');
            $table->date('period_start')->comment('結算區間起(GMT+8業務日)');
            $table->date('period_end')->comment('結算區間迄(GMT+8業務日)');
            $table->decimal('ggr_amount', 18, 2)->default(0)->comment('原始總GGR(=-Σwin_lose,經4123門檻/上限/加權後)');
            $table->decimal('bonus_rate', 8, 2)->default(0)->comment('獎金百分比%快照(公式的(1-獎金%)項)');
            $table->decimal('ggr_rate', 8, 2)->default(0)->comment('GGR百分比%快照');
            $table->decimal('condition_rate', 8, 2)->default(0)->comment('條件百分比%快照(4124命中階的應得%)');
            $table->decimal('admin_fee_rate', 8, 2)->default(0)->comment('管理費%快照(ΣGGR×管理費%)');
            $table->decimal('handling_fee', 18, 2)->default(0)->comment('手續費快照(固定金額)');
            $table->decimal('bonus_amount', 18, 2)->default(0)->comment('紅利金額設定(預留,來源後續批次定,前端暫不顯示)');
            $table->decimal('final_amount', 18, 2)->default(0)->comment('最終金額(公式算完)');
            $table->decimal('carry_in_amount', 18, 2)->default(0)->comment('发扬:上期結轉帶入的原始GGR');
            $table->decimal('carry_out_amount', 18, 2)->default(0)->comment('发扬:本期為負結轉帶出的原始GGR(結轉狀態由此欄!=0推導)');
            $table->json('calc_snapshot')->nullable()->comment('計算明細快照(各遊戲商GGR/加權/命中階/合格會員數,詳情彈窗與對帳依據)');
            $table->tinyInteger('payout_status')->default(0)->comment('發放狀態 0:未發放 1:已發放(GgrPayoutStatusEnum,狀態機屬發放批次)');
            $table->dateTime('paid_at')->nullable()->comment('發放時間');
            $table->string('remark', 255)->nullable()->comment('備註');
            $table->integer('admin')->nullable()->comment('管理者ID(發放/備註操作者,引擎寫入時為NULL)');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->index('general_agent_id', 'idx_ggr_settlement_general_agent');
            $table->index('agent_id', 'idx_ggr_settlement_agent');
            $table->unique(['plan_id', 'agent_id', 'period_start'], 'uk_ggr_settlement_plan_agent_period');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('ggr_settlements');
    }
};

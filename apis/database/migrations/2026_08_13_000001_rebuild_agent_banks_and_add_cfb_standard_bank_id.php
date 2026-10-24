<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 銀行表拆分第一期（2026-08-12 拍板，投影最小閉環）：絞殺者模式——不動現有 banks 功能，旁路建
// standard_banks / agent_banks / cash_flow_banks 三層結構，過渡期 banks 仍是權威、新表單向投影，穩定後才切換。
// 1) 重建 agent_banks 為投影表——以 standard_bank_id 為身份錨點（終態正確、免二次遷移；未映射的列投不過去，
//    天然逼映射補齊），並拿掉預埋空表揉進代理層的 cash_flow_id/bank_id 三元組（渠道綁定歸 cash_flow_banks，
//    可用性由 join 得出，同一事實只存一份）。預埋空表六站 0 列、零讀寫，直接重建無歷史包袱。
// 2) cash_flow_banks 加 nullable standard_bank_id 便利欄，由 bank:sync-projection 回填：照搬 banks.standard_bank_id
//    的成功模式，純加法零風險，讓「渠道 × 標準銀行」免三跳 join；既有 bank_id 消費者原樣不動，切換時 bank_id 退場即可。
// agent_bank_names 第一期留空不投、schema 相容不動。
return new class extends Migration
{
    public function up(): void
    {
        // preflight：agent_banks 應為空表（六站與 Dev 皆 0 列）；非 0 代表有未知寫入者，fail loud 停下人工確認
        $rows = DB::table('agent_banks')->count();
        if ($rows > 0) {
            throw new RuntimeException("agent_banks 應為 0 列的預埋空表，實際有 {$rows} 列，中止重建（請人工確認寫入來源）");
        }

        Schema::drop('agent_banks');

        SchemaMonitor::create('agent_banks', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('agent_group_id')
                ->comment('代理群組 ID，對應 agent_groups.id');

            $table->unsignedBigInteger('standard_bank_id')
                ->comment('標準銀行 ID（身份主錨），對應 standard_banks.id');

            $table->unsignedBigInteger('source_bank_id')
                ->comment('過渡期來源對照，對應 banks.id；權威反轉後退場（過渡期代碼清單 #14）');

            $table->decimal('min_amount', 20, 4)
                ->default(0)
                ->comment('代理層級最低金額限制（投影自 banks.min_amount）');

            $table->decimal('max_amount', 20, 4)
                ->default(0)
                ->comment('代理層級最高金額限制（0 = 不限制；投影自 banks.max_amount）');

            $table->tinyInteger('status')
                ->default(1)
                ->comment('狀態：1=啟用, 0=停用（投影自 banks.status）');

            $table->timestamps();

            $table->unique(['agent_group_id', 'standard_bank_id'], 'uq_group_standard_bank');
            $table->index(['agent_group_id', 'status'], 'idx_group_status');
        });

        // SchemaMonitor 靜默跳過防護：斷言重建生效，否則拋錯不讓 migration 被記為完成
        if (!Schema::hasColumn('agent_banks', 'standard_bank_id')) {
            throw new RuntimeException('agent_banks 重建未生效（SchemaMonitor 可能靜默跳過），中止');
        }

        SchemaMonitor::table('cash_flow_banks', function (Blueprint $table): void {
            $table->unsignedBigInteger('standard_bank_id')
                ->nullable()
                ->after('bank_id')
                ->comment('標準銀行 ID 便利欄，由 bank:sync-projection 投影回填；對應 standard_banks.id');

            $table->index('standard_bank_id', 'idx_cfb_standard_bank_id');
        });

        if (!Schema::hasColumn('cash_flow_banks', 'standard_bank_id')) {
            throw new RuntimeException('cash_flow_banks.standard_bank_id 未生效（SchemaMonitor 可能靜默跳過），中止');
        }
    }

    public function down(): void
    {
        SchemaMonitor::table('cash_flow_banks', function (Blueprint $table): void {
            $table->dropIndex('idx_cfb_standard_bank_id');
            $table->dropColumn('standard_bank_id');
        });

        Schema::dropIfExists('agent_banks');

        // 還原 2026_03_16_000001 預埋的原始三元組 schema（僅結構，資料本來就是投影可重建）
        SchemaMonitor::create('agent_banks', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('agent_group_id')->comment('代理群組 ID，對應 agent_groups.id');
            $table->unsignedBigInteger('bank_id')->comment('標準銀行 ID，對應 banks.id');
            $table->unsignedBigInteger('cash_flow_id')->comment('金流商戶 ID，對應 agent_cash_flows.id');
            $table->decimal('min_amount', 20, 4)->default(0)->comment('代理層級最低金額限制（不得超過 cash_flow_banks.min_amount）');
            $table->decimal('max_amount', 20, 4)->default(0)->comment('代理層級最高金額限制（0 = 不限制；不得超過 cash_flow_banks.max_amount）');
            $table->tinyInteger('status')->default(1)->comment('狀態：1=啟用, 0=停用');
            $table->timestamps();
            $table->unique(['agent_group_id', 'bank_id', 'cash_flow_id'], 'uq_group_bank_cashflow');
            $table->index(['agent_group_id', 'cash_flow_id', 'status'], 'idx_group_cashflow_status');
        });
    }
};

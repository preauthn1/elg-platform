<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 存款人工確認紀錄表 工單4463
 *
 * 一張需要人工確認的充值訂單一列，設計成多家金流商共用；首個使用者是 CCPayment 風險交易。
 * 舊代理後台（API_Agent）的手動上分與重置狀態只看 allow_credit，沒有列的充值訂單行為不變。
 * 寫入規則（result 與 allow_credit 必須一致、不准刪列）見 App\Models\DepositManualReview。
 *
 * reason／result 用 utf8mb4_0900_bin：排程以「SQL 撈列 → PHP 字串比對」兩段式判斷，
 * utf8mb4_bin 仍是 PAD SPACE（尾空白視為相等），0900_bin 才是 NO PAD，SQL 相等才等於 PHP 相等。
 *
 * 🔴 本表必須先於 API_Agent 的手動上分鎖上版：API_Agent 查本表失敗（含表不存在）一律不放行。
 */
return new class extends Migration
{
    public function up(): void
    {
        // SchemaMonitor::create() 遇到表已存在只告警、不拋錯；那時不補 CHECK，避免重複加約束讓 migration 失敗
        $existed = Schema::hasTable('deposit_manual_reviews');

        SchemaMonitor::create('deposit_manual_reviews', function (Blueprint $table) {
            $table->comment('存款人工確認紀錄（多金流商共用）');
            $table->increments('id')->comment('PK流水號');
            $table->unsignedInteger('order_id')->comment('充值訂單 orders.id；一張充值訂單最多一列');
            $table->unsignedInteger('agent_cash_flow_id')->comment('金流商戶 agent_cash_flows.id（排程逐商戶查詢與比對）');
            $table->unsignedInteger('cash_flow_id')->comment('金流商 cash_flows.id（冗餘存放，方便依金流商篩選）');
            $table->string('reason', 30)->charset('utf8mb4')->collation('utf8mb4_0900_bin')
                ->comment('需人工確認的原因 risky_transaction:金流商標記風險交易');
            $table->string('result', 30)->charset('utf8mb4')->collation('utf8mb4_0900_bin')->default('pending')
                ->comment('處理結果 pending:待處理 refunded_to_payer:退回付款人 refunded_to_merchant:退到營運方白名單地址');
            $table->unsignedTinyInteger('allow_credit')->default(0)
                ->comment('可否手動上分 0:不可 1:可；舊系統手動上分與重置狀態只看此欄');
            $table->json('proof')->nullable()
                ->comment('金流商給的依據，CCPayment: refund_record_id/refund_status/refund_to_address/payer_address；僅供查證');
            $table->timestamp('resolved_at')->nullable()->comment('確認處理結果的時間');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');

            $table->unique('order_id', 'uk_order');
            $table->index(['agent_cash_flow_id', 'reason', 'result', 'created_at'], 'idx_merchant_reason_result_created');
        });

        if (!$existed) {
            DB::statement('ALTER TABLE deposit_manual_reviews ADD CONSTRAINT chk_allow_credit CHECK (allow_credit IN (0, 1))');
        }
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('deposit_manual_reviews');
    }
};

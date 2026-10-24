<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * 把十個欄位的 comment 對齊 GLOSSARY.md 的正式名。
 *
 * 只改 comment，型別／nullable／default 逐一比對 information_schema 後原樣重述
 * （VWorld 實查，十欄與 create migration 零漂移）。**重述必須完整**——Laravel 的
 * `change()` 產生的是整句 `MODIFY COLUMN`，少寫一個屬性就是靜默把它改掉，
 * 大表上會從 INSTANT 掉成 rebuild。索引不歸 `change()` 管，故不重述。
 *
 * comment-only 變更走 ALGORITHM=INSTANT（MySQL 8.0.30 測試庫實測：普通表與 LIST
 * 分區表皆通過，`TABLE_ID` 前後未變即無 rebuild）。⚠️ 不要加 `->instant()->lock('none')`
 * ——`ALGORITHM=INSTANT, LOCK=NONE` 會被 MySQL 拒絕（1221 Incorrect usage）。
 * 真正的風險是 MDL：大表上要避開長查詢時段執行。
 *
 * 三類改動：
 * 1. **用詞對齊**：分成→分潤、轉換比例→入帳比例、群組→組別、英文原文→中文正式名。
 * 2. **事實更正**：`withdrawal_orders.agent_group_id` 的 comment 寫「金流商戶資料編號」，
 *    但它存的是支付組別——`app/Models/Agent.php:169` 的 hasMany 直接把
 *    `agent_cash_flows.agent_group_id` 對應到 `agents.cash_flow_group_id`，
 *    `app/Services/OrderTypeService.php:148` 也以 `$agent->cash_flow_group_id` 查它。
 *    `agent_cash_flows.agent_group_id` 同錯，兩個 Model 的 PHPDoc 一併修正。
 * 3. **雜訊清除**：`agent_cash_flows.rate` 的 comment 夾著變更歷史「(2025/05/05提升至9位)」
 *    ——那屬於 git log，不屬於欄位說明。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::table('agents', function (Blueprint $table) {
            $table->decimal('percentage', 10)->nullable()->comment('分潤比例')->change();
        });

        SchemaMonitor::table('summary_bet_records', function (Blueprint $table) {
            $table->decimal('percentage')->nullable()->comment('分潤比例')->change();
            $table->decimal('share_amount')->nullable()->comment('分潤金額')->change();
        });

        SchemaMonitor::table('agent_cash_flows', function (Blueprint $table) {
            $table->decimal('rate', 15, 9)->default(0)->comment('入帳比例')->change();
            $table->integer('agent_group_id')->default(0)->comment('支付組別編號')->change();
        });

        SchemaMonitor::table('total_bet_records', function (Blueprint $table) {
            $table->decimal('valid_bet_rate', 18, 4)->default(0)->comment('有效投注比例')->change();
            $table->decimal('bonus', 18, 4)->default(0)->comment('活動獎金')->change();
            $table->decimal('jackpot', 18, 4)->default(0)->comment('彩金')->change();
        });

        SchemaMonitor::table('withdrawal_orders', function (Blueprint $table) {
            $table->integer('agent_group_id')->default(0)->comment('支付組別編號')->change();
        });

        SchemaMonitor::table('vsport_bet_records', function (Blueprint $table) {
            $table->decimal('win_lose', 18, 4)->default(0)->comment('輸贏金額')->change();
        });
    }

    public function down(): void
    {
        SchemaMonitor::table('agents', function (Blueprint $table) {
            $table->decimal('percentage', 10)->nullable()->comment('百分比')->change();
        });

        SchemaMonitor::table('summary_bet_records', function (Blueprint $table) {
            $table->decimal('percentage')->nullable()->comment('分成百分比')->change();
            $table->decimal('share_amount')->nullable()->comment('分成金額')->change();
        });

        SchemaMonitor::table('agent_cash_flows', function (Blueprint $table) {
            $table->decimal('rate', 15, 9)->default(0)->comment('轉換比例(2025/05/05提升至9位)')->change();
            $table->integer('agent_group_id')->default(0)->comment('代理群組編號')->change();
        });

        SchemaMonitor::table('total_bet_records', function (Blueprint $table) {
            $table->decimal('valid_bet_rate', 18, 4)->default(0)->comment('轉換比例')->change();
            $table->decimal('bonus', 18, 4)->default(0)->comment('bonus')->change();
            $table->decimal('jackpot', 18, 4)->default(0)->comment('jackpot')->change();
        });

        SchemaMonitor::table('withdrawal_orders', function (Blueprint $table) {
            $table->integer('agent_group_id')->default(0)->comment('金流商戶資料編號')->change();
        });

        SchemaMonitor::table('vsport_bet_records', function (Blueprint $table) {
            // 還原時刻意保留原本的簡體「额」，down() 必須真的回到原狀
            $table->decimal('win_lose', 18, 4)->default(0)->comment('輸贏金额')->change();
        });
    }
};

<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * cash_flows 加回調來源 IP 白名單欄位（工單 target/tickets#4446）。
 *
 * 欄位語意與格式比照 `vendors.whitelist`（逗號分隔的 IP 字串），放在 cash_flows 而不是
 * agent_cash_flows.info：廠商的出口 IP 是**支付商**層級的屬性，對該支付商底下所有商戶、
 * 所有代理群組都是同一份，存進 per 商戶的設定會複製 N 份各自漂移。
 *
 * 🔴 讀取端是 `CallbackSourceGuardTrait`，設計為 **fail-closed**：白名單為空一律拒絕回調。
 * 因此部署順序不能顛倒——**先配好各站的 IP，再上讀取端**；反過來的話讀取端上線當下欄位還是
 * NULL，連真的回調都會被擋掉、存款中斷。本 migration 刻意先行上線（master 的 !2361），
 * 讓營運在程式生效之前就把 IP 配好。
 *
 * 🔴 **只建欄位、不預填任何 IP**：廠商給的 IP 會改（Ap8Pay 第一次給的五個就是錯的，dev 實測
 * 一個都對不上），寫死在 migration 裡的那份一旦過期，已經跑過的站台也不會再更新，徒增一個
 * 對不上現況的來源。各站的 IP 一律由營運從 Olympus 金流商管理頁配；2026-10-07 已確認
 * EasyPay／MaxPay／TngPin／AP8 在各站都已填妥。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::table('cash_flows', function (Blueprint $table): void {
            $table->text('whitelist')
                ->nullable()
                ->after('info')
                ->comment('回調來源 IP 白名單; ip1,ip2,ip3,...（比照 vendors.whitelist）');
        });

        // SchemaMonitor 靜默跳過防護：斷言欄位真的生效，否則拋錯不讓 migration 被記為完成
        if (!Schema::hasColumn('cash_flows', 'whitelist')) {
            throw new RuntimeException('cash_flows.whitelist 未生效（SchemaMonitor 可能靜默跳過），中止');
        }
    }

    public function down(): void
    {
        SchemaMonitor::table('cash_flows', function (Blueprint $table): void {
            $table->dropColumn('whitelist');
        });
    }
};

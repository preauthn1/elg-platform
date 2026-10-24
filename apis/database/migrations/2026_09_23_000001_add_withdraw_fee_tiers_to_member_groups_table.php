<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 會員群組平台手續費階梯 target/tickets#4353。
 *
 * NULL＝未啟用（沿用銀行手續費）。SchemaMonitor 遇列數門檻會靜默跳過，
 * 故操作後必補生效斷言——沒建成就 throw（.claude/rules/php.md）。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::table('member_groups', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('member_groups', 'withdraw_fee_tiers')) {
                $table->json('withdraw_fee_tiers')->nullable()->after('withdraw_daily_amount')->comment('平台手續費階梯 [{from,type,value}]；null=沿用銀行');
            }
        });

        if (!Schema::hasColumn('member_groups', 'withdraw_fee_tiers')) {
            throw new RuntimeException('member_groups.withdraw_fee_tiers 未建立（SchemaMonitor 因列數門檻跳過？）：請改用 Olympus 資料表結構維運加欄位（JSON、NULL、接在 withdraw_daily_amount 之後）後重跑');
        }
    }

    public function down(): void
    {
        SchemaMonitor::table('member_groups', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('member_groups', 'withdraw_fee_tiers')) {
                $table->dropColumn('withdraw_fee_tiers');
            }
        });
    }
};

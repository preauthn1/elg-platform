<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 提款單記錄「當日第幾筆」與「手續費來源」target/tickets#4353。
 *
 * withdrawal_orders 大站列數超過 SchemaMonitor 50 萬門檻（TPower／VWorld／Yes2Win），已列入
 * config/custom.php schema_monitor.row_count_exempt_tables 免列數檢查（ADD COLUMN 在 MySQL 8.0.29+ 為 INSTANT）。
 * 操作後仍補生效斷言——沒建成就 throw，不留「migration 已跑、欄位不在」的假象（.claude/rules/php.md）。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::table('withdrawal_orders', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('withdrawal_orders', 'withdraw_seq')) {
                $table->unsignedInteger('withdraw_seq')->default(0)->after('payment_cost')->comment('當日第幾筆(GMT+8)；0=非會員前台建單');
            }
            if (!SchemaMonitor::hasColumn('withdrawal_orders', 'tax_source')) {
                $table->tinyInteger('tax_source')->default(0)->after('withdraw_seq')->comment('手續費來源 0:銀行 1:群組階梯');
            }
        });

        if (!Schema::hasColumn('withdrawal_orders', 'withdraw_seq')) {
            throw new RuntimeException('withdrawal_orders.withdraw_seq 未建立（SchemaMonitor 因列數門檻跳過？）：請改用 Olympus 資料表結構維運加欄位（INT UNSIGNED、NOT NULL、預設 0、接在 payment_cost 之後）後重跑');
        }

        if (!Schema::hasColumn('withdrawal_orders', 'tax_source')) {
            throw new RuntimeException('withdrawal_orders.tax_source 未建立（SchemaMonitor 因列數門檻跳過？）：請改用 Olympus 資料表結構維運加欄位（TINYINT、NOT NULL、預設 0、接在 withdraw_seq 之後）後重跑');
        }
    }

    public function down(): void
    {
        SchemaMonitor::table('withdrawal_orders', function (Blueprint $table) {
            foreach (['tax_source', 'withdraw_seq'] as $column) {
                if (SchemaMonitor::hasColumn('withdrawal_orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

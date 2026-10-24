<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 支付商手續費（成本）欄位 target/tickets#4361。
 *
 * TPower／VWorld／Yes2Win 已先以 Olympus「資料表結構維運」（ALGORITHM=INSTANT）加好，此處 hasColumn 守門跳過；
 * 其餘站台由本 migration 建立。orders 超過 SchemaMonitor 列數門檻的站台會被靜默跳過，
 * 故每張表之後必補生效斷言——沒建成就 throw，不留「migration 已跑、欄位不在」的假象（.claude/rules/php.md）。
 */
return new class extends Migration
{
    private const array TABLES = ['orders', 'withdrawal_orders'];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            SchemaMonitor::table($tableName, function (Blueprint $table) use ($tableName) {
                if (!SchemaMonitor::hasColumn($tableName, 'payment_cost')) {
                    $table->decimal('payment_cost', 18, 4)->default(0)->after('tax')->comment('支付商手續費');
                }
            });

            if (!Schema::hasColumn($tableName, 'payment_cost')) {
                throw new RuntimeException(sprintf(
                    '%s.payment_cost 未建立（SchemaMonitor 因列數門檻跳過？）：請改用 Olympus 資料表結構維運加欄位（DECIMAL 18,4、NOT NULL、預設 0）後重跑',
                    $tableName,
                ));
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            SchemaMonitor::table($tableName, function (Blueprint $table) use ($tableName) {
                if (SchemaMonitor::hasColumn($tableName, 'payment_cost')) {
                    $table->dropColumn('payment_cost');
                }
            });
        }
    }
};

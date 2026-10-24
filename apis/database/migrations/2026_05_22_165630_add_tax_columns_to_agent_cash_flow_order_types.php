<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * 補回 agent_cash_flow_order_types 的手續費欄位至 migration 管理。
     *
     * 歷史 schema drift：production 6 個白標於某時點手動 ALTER 加上 tax / tax_percentage，
     * 但對應 migration 從未進 master（develop 956cfc044 的補丁未回流）。Me77 因漏跑 manual
     * ALTER 而完全缺欄；testing DB 從 migration 重建也缺欄，造成 TngPin 單元測試 1054 失敗。
     *
     * 本 migration 帶 hasColumn guard：production 重跑不會 duplicate，Me77 與 testing 會補齊。
     */
    public function up(): void
    {
        SchemaMonitor::table('agent_cash_flow_order_types', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('agent_cash_flow_order_types', 'tax')) {
                $table->decimal('tax', 18, 4)->default(0)->after('max_amount')->comment('手續費');
            }
            if (!SchemaMonitor::hasColumn('agent_cash_flow_order_types', 'tax_percentage')) {
                $table->decimal('tax_percentage', 8, 4)->default(0)->after('tax')->comment('手續費(百分比)');
            }
        });
    }

    public function down(): void
    {
        SchemaMonitor::table('agent_cash_flow_order_types', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('agent_cash_flow_order_types', 'tax_percentage')) {
                $table->dropColumn('tax_percentage');
            }
            if (SchemaMonitor::hasColumn('agent_cash_flow_order_types', 'tax')) {
                $table->dropColumn('tax');
            }
        });
    }
};

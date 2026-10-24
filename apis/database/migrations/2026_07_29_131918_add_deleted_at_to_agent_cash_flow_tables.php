<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * ACF 軟刪除（可回滾）：agent_cash_flows / agent_cash_flow_order_types 加 deleted_at。
     *
     * 舊系統（API_Frontend 等）不認識 deleted_at，但其查詢一律過濾 status=1；
     * 軟刪流程會同步把 ACF status 歸 0 作為舊系統保險（見 AgentCashFlowObserver::deleted）。
     */
    public function up(): void
    {
        SchemaMonitor::table('agent_cash_flows', function (Blueprint $table) {
            $table->softDeletes()->comment('軟刪除時間；NULL=有效（fix:acf-delete 可回滾刪除）');
        });

        SchemaMonitor::table('agent_cash_flow_order_types', function (Blueprint $table) {
            $table->softDeletes()->comment('軟刪除時間；NULL=有效（隨所屬 ACF 連動軟刪／還原）');
        });
    }

    public function down(): void
    {
        SchemaMonitor::table('agent_cash_flows', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        SchemaMonitor::table('agent_cash_flow_order_types', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};

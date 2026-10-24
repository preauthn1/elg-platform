<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('acf_cascade_deactivate_logs', function (Blueprint $table) {
            $table->comment('AgentCashFlow 下架連動 ACFOT 的審計（供還原命令反向重放）');
            $table->increments('id');
            $table->string('connection', 50)->nullable()->comment('執行當下的 DB connection（白標）');
            $table->integer('agent_cash_flow_id')->comment('觸發連動的 agent_cash_flows.id');
            $table->integer('agent_cash_flow_order_type_id')->comment('被連動處理的 agent_cash_flow_order_types.id');
            $table->integer('order_type_id')->comment('對應 order_types.id（冗余便於追查）');
            $table->string('action', 30)->comment('cascade_deactivate / restore');
            $table->unsignedTinyInteger('from_status')->comment('處理前的 acfot.status（還原時寫回此值）');
            $table->string('source', 30)->comment('觸發來源：observer / command');
            $table->string('detail', 255)->nullable()->comment('補充說明');
            $table->integer('restored_by_log_id')->nullable()->comment('已被哪筆 restore log 還原；NULL=尚未還原');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');

            $table->index(['agent_cash_flow_id', 'action'], 'idx_acf_action');
            $table->index('agent_cash_flow_order_type_id', 'idx_acfot');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('acf_cascade_deactivate_logs');
    }
};

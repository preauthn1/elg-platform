<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $table_name = 'summary_profit_share_payment_costs';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable($this->table_name)) {
            return;
        }
        Schema::create($this->table_name, function (Blueprint $table) {
            $table->comment('份桶佔成支付成本');
            $table->increments('id')->comment('流水號');
            $table->integer('type')->comment('類型; 1:充值, 2:提領');
            $table->dateTime('summary_time')->index('idx_summary_profit_share_payment_cost_summary_time')->comment('彙總時間(UTC)');
            $table->integer('agent_id')->index('idx_summary_profit_share_payment_cost_agent_id')->comment('代理ID');
            $table->integer('agent_cash_flow_id')->comment('agent_cash_flows.id');
            $table->decimal('cost', 5, 4)->default(0)->comment('盤費設定');
            $table->decimal('point', 18, 4)->default(0)->comment('充值金額');
            $table->decimal('payment_cost', 18, 4)->default(0)->comment('支付成本');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');

            $table->unique(['type', 'summary_time', 'agent_id', 'agent_cash_flow_id'], 'uni_summary_profit_share_payment_cost');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists($this->table_name);
    }
};

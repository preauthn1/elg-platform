<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $table_name = 'summary_profit_share_event_costs';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable($this->table_name)) {
            return;
        }
        Schema::create($this->table_name, function (Blueprint $table) {
            $table->comment('份桶佔成活動成本');
            $table->increments('id')->comment('流水號');
            $table->dateTime('summary_time')->index('idx_summary_profit_share_event_cost_summary_time')->comment('彙總時間(UTC)');
            $table->integer('agent_id')->index('idx_summary_profit_share_event_cost_agent_id')->comment('代理ID');
            $table->integer('transaction_type')->default(0)->comment('transaction_logs.type');
            $table->integer('event_id')->default(0)->comment('events.id, event_v2.id * 100000');
            $table->decimal('point', 18, 4)->default(0)->comment('活動成本');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
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

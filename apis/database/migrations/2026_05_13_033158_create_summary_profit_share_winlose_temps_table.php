<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $table_name = 'summary_profit_share_winlose_temps';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable($this->table_name)) {
            return;
        }
        Schema::create($this->table_name, function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->dateTime('summary_time')->index('idx_summary_profit_share_winlose_temp_summary_time')->comment('彙總時間(UTC)');
            $table->integer('agent_id')->index('idx_summary_profit_share_winlose_temp_agent_id')->comment('代理ID');
            $table->integer('shareholder_category_id')->index('idx_summary_profit_share_winlose_temp_sc_id')->comment('份桶類別ID');
            $table->integer('direct_percentage')->comment('直屬佔比百分比');
            $table->integer('team_percentage')->comment('團隊佔比百分比');
            $table->decimal('turnover', 18, 4)->default(0)->comment('營業額');
            $table->decimal('winlose', 18, 4)->default(0)->comment('輸贏');
            $table->decimal('win', 18, 4)->default(0)->comment('贏分');
            $table->decimal('game_spending', 18, 4)->default(0)->comment('遊戲支出');
            $table->decimal('game_profit', 18, 4)->default(0)->comment('遊戲盈利');
            $table->decimal('cost', 18, 4)->default(0)->comment('成本(盤費)');
            $table->decimal('payment_cost_original', 18, 4)->default(0)->comment('原始支付費用');
            $table->decimal('payment_cost', 18, 4)->default(0)->comment('支付費用');
            $table->decimal('event_cost_original', 18, 4)->default(0)->comment('原始活動獎金');
            $table->decimal('event_cost', 18, 4)->default(0)->comment('活動獎金');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0);

            $table->unique(['summary_time', 'agent_id', 'shareholder_category_id'], 'uni_summary_profit_share_winlose_temp');
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

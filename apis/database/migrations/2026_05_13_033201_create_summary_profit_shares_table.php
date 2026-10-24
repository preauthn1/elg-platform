<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $table_name = 'summary_profit_shares';

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
            $table->dateTime('summary_time')->index('idx_summary_profit_share_summary_time')->comment('彙總時間(UTC)');
            $table->integer('agent_id')->index('idx_summary_profit_share_agent_id')->comment('代理ID');
            $table->integer('parent_id')->index('idx_summary_profit_share_parent_id')->comment('父代理ID');
            $table->integer('shareholder_category_id')->default(0);
            $table->string('hierarchy_path', 191)->index('idx_summary_profit_share_hierarchy_path')->comment('代理階層路徑');
            $table->decimal('company_rate', 5, 4)->default(0)->comment('公司佔成比例');
            $table->integer('share_point')->comment('代理股份');
            $table->decimal('wallet_balance', 18, 4)->default(0)->comment('代理錢包餘額');
            $table->decimal('direct_win_lose_original', 18, 4)->default(0)->comment('直屬玩家輸贏(原始數據)');
            $table->decimal('direct_win_lose', 18, 4)->default(0)->comment('直屬玩家輸贏');
            $table->decimal('direct_real_win_lose', 18, 4)->default(0)->comment('直屬實際輸贏(扣除遊戲盤費, 活動, 支付成本)\\n');
            $table->decimal('direct_rate', 5, 4)->default(0)->comment('代理設定直屬玩家佔成');
            $table->decimal('direct_profit', 18, 4)->default(0)->comment('直屬玩家代理占比输赢');
            $table->decimal('team_win_lose_original', 18, 4)->default(0)->comment('下級貢獻輸贏(原始數據)');
            $table->decimal('team_win_lose', 18, 4)->default(0)->comment('下級貢獻輸贏');
            $table->decimal('team_win_lose_extra', 18, 4)->default(0)->comment('下級貢獻輸贏(額外)');
            $table->decimal('team_rate', 5, 4)->default(0)->comment('下級佔成');
            $table->decimal('team_profit', 18, 4)->default(0)->comment('下級代理占比输赢');
            $table->decimal('weighting', 9, 8)->default(0)->comment('權重');
            $table->decimal('profit', 18, 4)->default(0)->comment('實際輸贏');
            $table->decimal('game_cost', 18, 4)->default(0)->comment('遊戲盤費');
            $table->decimal('event_cost', 18, 4)->default(0)->comment('活動獎金加上權重後');
            $table->decimal('payment_cost', 18, 4)->default(0)->comment('支付成本加上權重後');
            $table->boolean('status')->default(false)->index('idx_summary_profit_share_status')->comment('派發狀態');
            $table->text('remark')->nullable();
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0);

            $table->unique(['summary_time', 'agent_id', 'shareholder_category_id'], 'uni_summary_profit_share_');
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

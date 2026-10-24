<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $table_name = 'summary_profit_share_winloses';

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
            $table->dateTime('summary_time')->index('idx_summary_profit_share_winlose_summary_time')->comment('彙總時間(UTC)');
            $table->integer('agent_id')->index('idx_summary_profit_share_winlose_agent_id')->comment('代理ID');
            $table->integer('vendor_id')->index('idx_summary_profit_share_winlose_vendor_id')->comment('廠商ID; 大於0為vendor_id, -1:獎勵, -2:v-reward, -3:支付手續費, -4:簡訊手續費, -5:三層代獎勵');
            $table->integer('game_type_id')->index('idx_summary_profit_share_winlose_game_type_id')->comment('遊戲類別');
            $table->decimal('turnover', 18, 4)->default(0)->comment('營業額');
            $table->decimal('winlose', 18, 4)->default(0)->comment('輸贏');
            $table->decimal('game_spending', 18, 4)->default(0)->comment('遊戲支出');
            $table->decimal('game_profit', 18, 4)->default(0)->comment('遊戲盈利');
            $table->decimal('cost', 18, 4)->default(0)->comment('成本(盤費)');
            $table->string('memo', 100)->comment('備註');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0);

            $table->unique(['summary_time', 'agent_id', 'vendor_id', 'game_type_id'], 'uni_summary_profit_share_winlose');
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

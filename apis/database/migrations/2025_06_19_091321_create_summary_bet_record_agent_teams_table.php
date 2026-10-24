<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        SchemaMonitor::create('summary_bet_record_agent_teams', function (Blueprint $table) {
            $table->dateTime('summary_time')->index()->comment('彙整時間');
            $table->dateTime('data_time')->index()->comment('數據時間');
            $table->unsignedInteger('agent_id')->index()->comment('代理系統編號');
            $table->unsignedInteger('vendor_id')->index()->comment('遊戲商系統編號');
            $table->integer('game_id')->default(0)->index()->comment('遊戲系統編號');
            $table->decimal('bet_direct', 18, 4)->default(0)->comment('投注金額-直屬');
            $table->decimal('bet_team', 18, 4)->default(0)->comment('投注金額-團隊');
            $table->decimal('win_lose_direct', 18, 4)->default(0)->comment('贏得金額-直屬');
            $table->decimal('win_lose_team', 18, 4)->default(0)->comment('贏得金額-團隊');
            $table->decimal('rolling_chip_direct', 18, 4)->default(0)->comment('禮碼-直屬');
            $table->decimal('rolling_chip_team', 18, 4)->default(0)->comment('禮碼-團隊');
            $table->decimal('commission_direct', 18, 4)->default(0)->comment('佣金-直屬');
            $table->decimal('commission_team', 18, 4)->default(0)->comment('佣金-團隊');
            $table->decimal('jackpot_direct', 18, 4)->default(0)->comment('彩金-直屬');
            $table->decimal('jackpot_team', 18, 4)->default(0)->comment('彩金-團隊');
            $table->decimal('v_jackpot_direct', 18, 4)->default(0)->comment('v_jackpot-直屬');
            $table->decimal('v_jackpot_team', 18, 4)->default(0)->comment('v_jackpot-團隊');
            $table->decimal('cost_direct', 18, 4)->default(0)->comment('成本-直屬');
            $table->decimal('cost_team', 18, 4)->default(0)->comment('成本-團隊');
            $table->decimal('share_amount_direct')->default(0)->comment('分成金額-直屬');
            $table->decimal('share_amount_team')->default(0)->comment('分成金額-團隊');
            $table->integer('record_count_direct')->default(0)->comment('資料計數-直屬');
            $table->integer('record_count_team')->default(0)->comment('資料計數-團隊');
            $table->tinyInteger('correction')->default(0)->comment('改正標示');
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable()->useCurrent();

            $table->unique(['summary_time', 'data_time', 'agent_id', 'vendor_id', 'game_id'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('summary_bet_record_agent_teams');
    }
};

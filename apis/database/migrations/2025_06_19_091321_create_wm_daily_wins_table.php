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
        SchemaMonitor::create('wm_daily_wins', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->string('player_name', 191)->comment('玩家名稱(Wallet平台)');
            $table->string('player_token', 191)->comment('使用者授權碼');
            $table->string('skin', 191)->comment('代理/下線');
            $table->string('currency', 191)->comment('幣別');
            $table->decimal('cash_bet', 18, 4)->comment('現金總投注額');
            $table->decimal('cash_win', 18, 4)->nullable()->comment('現金總贏分');
            $table->decimal('bonus_bet', 18, 4)->comment('紅利總投注額');
            $table->decimal('bonus_win', 18, 4)->nullable()->comment('紅利總贏分');
            $table->decimal('free_round_bet', 18, 4)->comment('免費回合總投注額');
            $table->decimal('free_round_win', 18, 4)->nullable()->comment('免費回合總贏分');
            $table->decimal('jack_pot_bet', 18, 4)->comment('彩池總投注額');
            $table->decimal('jack_pot_win', 18, 4)->nullable()->comment('彩池總贏分');
            $table->string('gamerounds', 191)->comment('現金與紅利總注單量');
            $table->string('freerounds', 191)->comment('免費回合總注單量');
            $table->string('date', 191)->nullable()->comment('時間(+0)');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('wm_daily_wins');
    }
};

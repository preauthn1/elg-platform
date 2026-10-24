<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateWmBetRecord extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('wm_bet_records', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->string('player_name')->comment('玩家名稱(Wallet平台)');
            $table->string('player_token')->comment('使用者授權碼');
            $table->string('skin')->comment('代理/下線');
            $table->string('currency')->comment('幣別');
            $table->decimal('cash_bet', 13, 7)->comment('現金總投注額');
            $table->decimal('cash_win', 13, 7)->nullable()->comment('現金總贏分');
            $table->decimal('bonus_bet', 13, 7)->comment('紅利總投注額');
            $table->decimal('bonus_win', 13, 7)->nullable()->comment('紅利總贏分');
            $table->decimal('free_round_bet', 13, 7)->comment('免費回合總投注額');
            $table->decimal('free_round_win', 13, 7)->nullable()->comment('免費回合總贏分');
            $table->decimal('jack_pot_bet', 13, 7)->comment('彩池總投注額');
            $table->decimal('jack_pot_win', 13, 7)->nullable()->comment('彩池總贏分');
            $table->string('gamerounds')->comment('現金與紅利總注單量');
            $table->string('freerounds')->comment('免費回合總注單量');
            $table->string('date')->nullable()->comment('時間(+0)');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
        });
    }
}

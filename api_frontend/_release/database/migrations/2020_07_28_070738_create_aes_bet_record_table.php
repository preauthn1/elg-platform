<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAesBetRecordTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('aes_bet_records', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->string('gameType')->comment('遊戲類別');
            $table->decimal('winAmount', 18, 4)->comment('贏得金額');
            $table->dateTime('bet_time')->comment('投注時間');
            $table->string('settleStatus')->comment('');
            $table->string('gameInfo')->comment('遊戲資訊');
            $table->decimal('realWinAmount', 18, 4)->comment('實際贏得金額');
            $table->decimal('realBetAmount', 18, 4)->comment('實際投注金額');
            $table->dateTime('updateTime')->comment('更新注單時間');
            $table->string('userId')->comment('玩家名稱');
            $table->string('betType')->comment('投注類別');
            $table->string('platform')->comment('平台');
            $table->string('txStatus')->comment('狀態');
            $table->decimal('betAmount', 18, 4)->comment('投注金額');
            $table->string('platformTxId')->comment('');
            $table->string('gameCode')->comment('遊戲編號');
            $table->string('currency')->comment('幣別');
            $table->decimal('jackpotWinAmount', 18, 4)->comment('彩池贏得金額');
            $table->decimal('jackpotBetAmount', 18, 4)->comment('彩池投注金額');
            $table->string('bet_id')->comment('ID');
            $table->string('turnover')->comment('');
            $table->string('roundId')->comment('');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('aes_bet_records');
    }
}

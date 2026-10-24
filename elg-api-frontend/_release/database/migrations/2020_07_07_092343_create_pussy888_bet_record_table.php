<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreatePussy888BetRecordTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('pussy888_bet_records', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->integer('class_id')->comment('ClassID');
            $table->string('account')->comment('pussy888玩家帳號');
            $table->dateTime('bet_time')->comment('注單生成時間');
            $table->dateTime('bet_time_utc')->comment('注單生成時間(UTC)');
            $table->decimal('begin_balance', 18, 4)->comment('遊戲開始金額');
            $table->decimal('end_balance', 18, 4)->comment('遊戲結束金額');                        
            $table->integer('game_id')->comment('遊戲ID');
            $table->string('game_name')->comment('遊戲名稱');
            $table->integer('line_num');
            $table->string('log_data_str')->comment('遊戲紀錄');
            $table->integer('log_data_type');
            $table->integer('round_no');
            $table->integer('row_num');
            $table->integer('table_id')->comment('桌號');
            $table->decimal('win', 18, 4)->comment('派彩金額');
            $table->decimal('bet', 18, 4)->comment('下注金額');
            $table->integer('cday');
            $table->integer('cno');
            $table->string('uuid', '50')->comment('uuid');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('pussy888_bet_records');
    }
}

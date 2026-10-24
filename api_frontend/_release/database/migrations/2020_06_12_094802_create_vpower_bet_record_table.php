<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateVpowerBetRecordTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('vpower_bet_records', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->string('uid')->comment('');
            $table->string('gameid')->comment('遊戲ID');
            $table->string('tableid')->comment('桌子ID');
            $table->string('username')->comment('玩家名稱');
            $table->decimal('pay', 13, 7)->comment('下注金額');
            $table->decimal('win', 13, 7)->nullable()->comment('派彩金額');
            $table->decimal('startscore', 13, 7)->comment('遊戲開始金額');
            $table->decimal('finalscore', 13, 7)->comment('遊戲結束金額');
            $table->string('time')->comment('時間');
            $table->string('utc_time')->comment('時間(UTC)');
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
        Schema::dropIfExists('vpower_bet_records');
    }
}

<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateIgsBetRecordTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('igs_bet_records', function (Blueprint $table) {
            $table->increments('id');
            $table->string('username')->comment('玩家帳號')->index();
            $table->string('switch')->comment('收支類型');
            $table->string('trade_type')->comment('交易類型');
            $table->string('trade_sn')->comment('交易編號');
            $table->string('remark')->comment('備註');
            $table->string('amount')->comment('收支金額');
            $table->string('after_amount')->comment('交易後餘額');
            $table->string('trade_at')->comment('交易時間');
            $table->string('utc_trade_at')->comment('交易時間 UTC');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('igs_bet_records');
    }
}

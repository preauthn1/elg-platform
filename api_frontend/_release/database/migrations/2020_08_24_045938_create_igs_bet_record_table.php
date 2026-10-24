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
            $table->integer('username')->comment('玩家帳號')->index();
            $table->integer('switch')->comment('收支類型');
            $table->integer('trade_type')->comment('交易類型');
            $table->string('trade_sn', 50)->comment('交易編號');
            $table->string('remark', 50)->comment('備註');
            $table->decimal('amount', 18, 4)->comment('收支金額');
            $table->decimal('after_amount', 18, 4)->comment('交易後餘額');
            $table->dateTime('trade_at')->comment('交易時間');
            $table->dateTime('utc_trade_at')->comment('交易時間 UTC');
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

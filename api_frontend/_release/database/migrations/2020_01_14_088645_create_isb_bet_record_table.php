<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateIsbBetRecordTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('isb_bet_records', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->string('playerid', 64)->comment('玩家編號');
            $table->string('operator', 64)->comment('代理名稱');
            $table->string('currency', 6)->nullable()->comment('幣別');
            $table->string('sessionid', 64)->nullable()->comment('SESSION ID');
            $table->string('gameid', 32)->nullable()->comment('遊戲編號');
            $table->string('roundid', 64)->nullable()->comment('局號');
            $table->string('status', 10)->nullable()->comment('狀態');
            $table->string('channel', 10)->nullable()->comment('Desktop or Mobile');
            $table->string('type', 32)->nullable()->comment('類型：BET、WIN、CANCEL、FREE_ROUND_BET、FREE_ROUND_WIN');
            $table->string('transactionid', 64)->nullable()->comment('交易編號');
            $table->dateTime('time')->comment('投注時間(GMT)');
            $table->decimal('amount', 13, 7)->nullable()->comment('投注金額');
            $table->decimal('balance', 13, 7)->nullable()->comment('餘額');
            $table->decimal('jpc', 13, 7)->nullable()->comment('Amount of JP contribution, in cents.');
            $table->decimal('jpw', 13, 7)->nullable()->comment('Amount of JP wins, in cents.');
            $table->decimal('jpw_jpc', 13, 7)->nullable()->comment('NGR = bet - win + jpw_from_jpc - jpc.');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
        });

        Schema::table('isb_bet_records', function (Blueprint $table) {
            $table->index('playerid');
            $table->index('gameid');
            $table->index('roundid');
            $table->index('time');
        });
    }
}
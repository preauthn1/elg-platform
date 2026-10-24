<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAce333BetRecordTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('ace333_bet_records', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->string('playerID')->comment('玩家編號');
            $table->string('extPlayerID')->comment('玩家編號');
            $table->string('gameID')->comment('遊戲編號');
            $table->string('transactionType')->comment('交易類型(BT-Bet,TU-Top up, WD,Withdraw,JP Jackpot)');
            $table->string('referenceID')->comment('交易編號(唯一值)');
            $table->string('status')->comment('狀態C-Completed');
            $table->dateTime('created')->comment('投注時間');
            $table->dateTime('updated')->comment('投注更新時間');
            $table->decimal('betAmount', 16, 6)->default(0)->comment('投注金額');
            $table->decimal('winAmount', 16, 6)->default(0)->comment('輸贏金額');
            $table->string('jackpotModule')->comment('彩金模式');
            $table->decimal('jackpotContributionAmt', 16, 6)->default(0)->comment('彩金金額');
            $table->string('currency')->comment('幣種');
            $table->string('resultUrl')->comment('開獎結果url');
            $table->string('roundDetails')->comment('詳情');
            $table->string('platform')->comment('遊玩平台');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });
    }
}
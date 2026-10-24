<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreatePgRoundBetRecordTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('pg_round_bet_records', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->string('TransactionId')->comment('遊戲紀錄詳情ID');
            $table->tinyInteger('Status')->comment('交易狀態');
            $table->decimal('Amount', 13, 7)->comment('加到用戶的金額');
            $table->string('Time')->comment('交易時間(UTC+0)');
            $table->string('ProductGroup')->comment('產品組別');
            $table->string('ExternalUserId')->comment('外部遊戲名稱');
            $table->string('GamesessionId')->comment('遊戲sessionID');
            $table->string('GamesessionState')->comment('遊戲session狀態');
            $table->string('GameId')->comment('遊戲ID');
            $table->string('RoundId')->comment('回合ID');
            $table->string('RoundData')->comment('詳細回合數據');
            $table->decimal('RoundLoss', 13, 7)->comment('本回合總投注額');
            $table->decimal('JackpotLoss', 13, 7)->comment('貢獻彩金');
            $table->decimal('JackpotGain', 13, 7)->comment('獲得彩金');
            $table->string('Currency')->comment('幣種');
            $table->string('ExternalTransactionId')->comment('外部遊戲ID');
            $table->decimal('Balance', 13, 7)->comment('錢包金額');
            $table->string('NumRounds')->comment('回合數');
            $table->decimal('TotalLoss', 13, 7)->comment('投注金額');
            $table->decimal('TotalGain', 13, 7)->comment('贏得金額');
            $table->string('ExternalFreegameId')->comment('外部免費遊戲ID');
            $table->string('Channel')->comment('頻道');
            $table->string('MessageId')->comment('訊息ID');
            $table->string('MessageTimestamp')->comment('獲得訊息時間');
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
        Schema::dropIfExists('pg_round_bet_records');
    }
}

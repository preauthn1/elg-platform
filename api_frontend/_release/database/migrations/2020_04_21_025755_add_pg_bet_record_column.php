<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddPgBetRecordColumn extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('pg_bet_records', function (Blueprint $table)
        {
            $table->dropColumn('message');
            $table->string('TransactionId')->comment('遊戲紀錄詳情ID')->after('id');
            $table->string('ProductGroup')->comment('產品組別')->after('TransactionId');
            $table->string('ExternalUserId')->comment('外部遊戲名稱')->after('ProductGroup');
            $table->string('GamesessionId')->comment('遊戲sessionID')->after('ExternalUserId');
            $table->string('GameId')->comment('遊戲ID')->after('GamesessionId');         
            $table->string('Currency')->comment('幣別')->after('GameId');
            $table->string('ExternalTransactionId')->comment('外部遊戲詳情ID')->after('Currency');  
            $table->decimal('Balance', 13, 7)->comment('錢包金額')->after('ExternalTransactionId');
            $table->string('NumRounds')->comment('次數')->after('Balance');
            $table->decimal('TotalLoss', 13, 7)->comment('投注金額')->after('NumRounds');
            $table->decimal('TotalGain', 13, 7)->comment('贏得金額')->after('TotalLoss');
            $table->string('GamesessionStarted')->comment('遊戲開始時間')->after('TotalGain');
            $table->string('GamesessionFinished')->comment('遊戲結束時間')->after('GamesessionStarted');
            $table->decimal('ExchangeRate', 13, 7)->comment('匯率')->after('GamesessionFinished');
            $table->string('ContextId')->nullable()->comment('開始遊戲時指定的上下文ID')->after('ExchangeRate');
            $table->string('ClientIP')->nullable()->comment('客戶IP')->after('ContextId');
            $table->string('ExternalFreegameId')->nullable()->comment('外部免費遊戲ID')->after('ClientIP');
            $table->decimal('JackpotLoss', 13, 7)->comment('貢獻彩金')->after('ExternalFreegameId');
            $table->decimal('JackpotGain', 13, 7)->comment('獲得彩金')->after('JackpotLoss');
            $table->decimal('FreegameRounds', 13, 7)->nullable()->comment('免費遊戲次數')->after('JackpotGain');
            $table->decimal('FreegameBet', 13, 7)->nullable()->comment('免費遊戲投注金額')->after('FreegameRounds');
            $table->decimal('FreegameWin', 13, 7)->nullable()->comment('免費遊戲贏得金額')->after('FreegameBet');
            $table->string('Channel')->nullable()->comment('頻道')->after('FreegameWin');
            $table->string('MessageId')->comment('訊息ID')->after('Channel');
            $table->integer('MessageTimestamp')->comment('獲得訊息時間')->after('MessageId');
        });
    }
}

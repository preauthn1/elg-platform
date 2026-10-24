<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        SchemaMonitor::create('pg_bet_records', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->string('TransactionId', 191)->index()->comment('遊戲紀錄詳情ID');
            $table->string('ProductGroup', 191)->comment('產品組別');
            $table->string('ExternalUserId', 191)->index()->comment('外部遊戲名稱');
            $table->string('GamesessionId', 191)->comment('遊戲sessionID');
            $table->string('GameId', 191)->index()->comment('遊戲ID');
            $table->string('Currency', 191)->comment('幣別');
            $table->string('ExternalTransactionId', 191)->comment('外部遊戲詳情ID');
            $table->decimal('Balance', 18, 4)->comment('錢包金額');
            $table->string('NumRounds', 191)->comment('次數');
            $table->decimal('TotalLoss', 18, 4)->comment('投注金額');
            $table->decimal('TotalGain', 18, 4)->comment('贏得金額');
            $table->string('GamesessionStarted', 191)->comment('遊戲開始時間');
            $table->string('GamesessionFinished', 191)->comment('遊戲結束時間');
            $table->decimal('ExchangeRate', 18, 4)->comment('匯率');
            $table->string('ContextId', 191)->nullable()->comment('開始遊戲時指定的上下文ID');
            $table->string('ClientIP', 191)->nullable()->comment('客戶IP');
            $table->string('ExternalFreegameId', 191)->nullable()->comment('外部免費遊戲ID');
            $table->decimal('JackpotLoss', 18, 4)->comment('貢獻彩金');
            $table->decimal('JackpotGain', 18, 4)->comment('獲得彩金');
            $table->decimal('FreegameRounds', 18, 4)->nullable()->comment('免費遊戲次數');
            $table->decimal('FreegameBet', 18, 4)->nullable()->comment('免費遊戲投注金額');
            $table->decimal('FreegameWin', 18, 4)->nullable()->comment('免費遊戲贏得金額');
            $table->string('Channel', 191)->nullable()->comment('頻道');
            $table->string('MessageId', 191)->comment('訊息ID');
            $table->string('MessageTimestamp', 191)->comment('獲得訊息時間');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('pg_bet_records');
    }
};

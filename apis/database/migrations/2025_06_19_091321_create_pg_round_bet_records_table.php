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
        SchemaMonitor::create('pg_round_bet_records', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->string('TransactionId', 191)->index()->comment('遊戲紀錄詳情ID');
            $table->tinyInteger('Status')->comment('交易狀態');
            $table->decimal('Amount', 18, 4)->comment('加到用戶的金額');
            $table->string('OldTime', 191)->comment('交易時間(UTC+0)');
            $table->timestamp('Time')->nullable()->index()->comment('投注時間');
            $table->string('ProductGroup', 191)->comment('產品組別');
            $table->string('ExternalUserId', 50)->index()->comment('外部遊戲名稱');
            $table->string('GamesessionId', 50)->comment('遊戲sessionID');
            $table->string('GamesessionState', 50)->comment('遊戲session狀態');
            $table->string('GameId', 50)->index()->comment('遊戲ID');
            $table->string('RoundId', 50)->comment('回合ID');
            $table->string('RoundData', 191)->nullable()->comment('詳細回合數據');
            $table->decimal('RoundLoss', 18, 4)->comment('本回合總投注額');
            $table->decimal('JackpotLoss', 18, 4)->comment('貢獻彩金');
            $table->decimal('JackpotGain', 18, 4)->comment('獲得彩金');
            $table->string('Currency', 10)->comment('幣種');
            $table->string('ExternalTransactionId', 50)->comment('外部遊戲ID');
            $table->decimal('Balance', 18, 4)->comment('錢包金額');
            $table->string('NumRounds', 191)->comment('回合數');
            $table->decimal('TotalLoss', 18, 4)->comment('投注金額');
            $table->decimal('TotalGain', 18, 4)->comment('贏得金額');
            $table->string('ExternalFreegameId', 191)->nullable()->comment('外部免費遊戲ID');
            $table->string('Channel', 10)->comment('頻道');
            $table->string('MessageId', 50)->comment('訊息ID');
            $table->string('MessageTimestamp', 191)->comment('獲得訊息時間');
            $table->string('info', 500)->nullable()->comment('response data');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('pg_round_bet_records');
    }
};

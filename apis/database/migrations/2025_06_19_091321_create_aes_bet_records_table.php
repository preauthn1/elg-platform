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
        SchemaMonitor::create('aes_bet_records', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->string('gameType', 191)->comment('遊戲類別');
            $table->decimal('winAmount', 18, 4)->comment('贏得金額');
            $table->dateTime('bet_time')->comment('投注時間');
            $table->string('settleStatus', 191);
            $table->string('gameInfo', 191)->comment('遊戲資訊');
            $table->decimal('realWinAmount', 18, 4)->comment('實際贏得金額');
            $table->decimal('realBetAmount', 18, 4)->comment('實際投注金額');
            $table->dateTime('updateTime')->index()->comment('更新注單時間');
            $table->string('userId', 191)->index()->comment('玩家名稱');
            $table->string('betType', 191)->comment('投注類別');
            $table->string('platform', 191)->comment('平台');
            $table->string('txStatus', 191)->comment('狀態');
            $table->decimal('betAmount', 18, 4)->comment('投注金額');
            $table->string('platformTxId', 191);
            $table->string('gameCode', 191)->index()->comment('遊戲編號');
            $table->string('currency', 191)->comment('幣別');
            $table->decimal('jackpotWinAmount', 18, 4)->comment('彩池贏得金額');
            $table->decimal('jackpotBetAmount', 18, 4)->comment('彩池投注金額');
            $table->string('bet_id', 191)->index()->comment('ID');
            $table->string('turnover', 191);
            $table->string('roundId', 191);
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');

            $table->index(['bet_time', 'txStatus']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('aes_bet_records');
    }
};

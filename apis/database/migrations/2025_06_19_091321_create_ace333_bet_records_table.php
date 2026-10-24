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
        SchemaMonitor::create('ace333_bet_records', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->string('playerID', 30)->index()->comment('玩家編號');
            $table->string('extPlayerID', 30)->comment('玩家編號');
            $table->string('gameID', 20)->index()->comment('遊戲編號');
            $table->string('transactionType', 10)->comment('交易類型(BT:Bet, TU:Top up, WD:Withdraw, JP:Jackpot)');
            $table->string('referenceID', 50)->index()->comment('交易編號(唯一值)');
            $table->string('status', 10)->comment('狀態C-Completed');
            $table->dateTime('created')->index()->comment('投注時間');
            $table->dateTime('updated')->comment('投注更新時間');
            $table->decimal('betAmount', 18, 4)->default(0)->comment('投注金額');
            $table->decimal('winAmount', 18, 4)->default(0)->comment('輸贏金額');
            $table->string('jackpotModule', 20)->comment('彩金模式');
            $table->decimal('jackpotContributionAmt', 18, 4)->default(0)->comment('彩金金額');
            $table->string('currency', 5)->comment('幣種');
            $table->string('resultUrl', 191)->comment('開獎結果url');
            $table->string('roundDetails', 50)->comment('詳情');
            $table->string('platform', 20)->comment('遊玩平台');
            $table->text('info')->nullable()->comment('info');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');

            $table->unique(['referenceID'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('ace333_bet_records');
    }
};

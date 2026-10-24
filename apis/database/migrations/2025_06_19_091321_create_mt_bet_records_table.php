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
        SchemaMonitor::create('mt_bet_records', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->string('rowID', 50)->unique()->comment('遊戲紀錄詳情ID');
            $table->string('playerName', 50)->index()->comment('商戶用戶名稱');
            $table->string('gameDate', 191)->comment('遊戲時間');
            $table->string('gameCode', 20)->index()->comment('遊戲代碼');
            $table->tinyInteger('gameType')->comment('遊戲類型');
            $table->decimal('betAmount', 18, 4)->comment('下注金額');
            $table->decimal('winAmount', 18, 4)->nullable()->comment('贏得金額');
            $table->decimal('commissionable', 18, 4)->comment('有效投注量');
            $table->decimal('roomFee', 18, 4)->comment('房費');
            $table->decimal('income', 18, 4)->comment('赢得金額-下注金額-房費');
            $table->decimal('progressive_wins', 18, 4)->nullable()->comment('貢獻彩金');
            $table->decimal('progressive_share', 18, 4)->nullable()->comment('獲得彩金');
            $table->integer('merchantId')->default(0)->comment('商戶ID');
            $table->string('utc_gameDate', 191)->nullable()->index()->comment('遊戲時間(UTC+0)');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('mt_bet_records');
    }
};

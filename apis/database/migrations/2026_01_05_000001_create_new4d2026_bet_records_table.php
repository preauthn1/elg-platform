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
        SchemaMonitor::create('new4d2026_bet_records', function (Blueprint $table) {
            $table->id();
            // 基本欄位
            $table->string('username', 191)->comment('玩家帳號');
            // 交易欄位
            $table->string('bet_id', 191)->comment('注單號');
            $table->string('game_code', 191)->comment('遊戲代碼');
            // 金額欄位
            $table->decimal('bet', 18, 4)->default(0)->comment('投注額');
            $table->decimal('valid_bet', 18, 4)->default(0)->comment('有效投注額');
            $table->decimal('win', 18, 4)->default(0)->comment('派彩金額');
            $table->decimal('win_lose', 18, 4)->default(0)->comment('輸贏金額');
            $table->decimal('bonus', 18, 4)->default(0)->comment('額外獎金/錦標賽獎金');
            $table->decimal('jackpot', 18, 4)->default(0)->comment('彩池獎金');
            $table->decimal('rolling_chip', 18, 4)->default(0)->comment('投注禮碼金額');
            // 狀態與時間欄位
            $table->timestamp('bet_time')->comment('投注時間');
            $table->timestamp('payout_time')->nullable()->comment('結算時間');
            $table->tinyInteger('status')->default(0)->comment('狀態 0:建立注單,1:已派彩,2:取消注單');
            $table->text('info')->nullable()->comment('response data');
            $table->timestamps();

            // 索引
            $table->index('username');
            $table->index('bet_id');
            $table->index('bet_time');
            $table->index('payout_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('new4d2026_bet_records');
    }
};

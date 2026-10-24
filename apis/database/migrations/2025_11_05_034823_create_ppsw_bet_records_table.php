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
        SchemaMonitor::create('ppsw_bet_records', function (Blueprint $table) {
            $table->id();
            // 基本欄位
            $table->integer('vendor_info_id')->default(0)->comment('遊戲商設定編號');
            $table->integer('member_id')->default(0)->comment('會員編號');
            $table->string('username', 50)->comment('玩家帳號 playerId');
            // 交易欄位
            $table->string('bet_id', 50)->comment('注單號 roundId');
            $table->string('reference_no', 50)->comment('交易號');
            $table->string('game_code', 50)->comment('游戏ID');
            // 金額欄位
            $table->decimal('bet', 18, 4)->default(0.0000)->comment('投注額');
            $table->decimal('valid_bet', 18, 4)->default(0.0000)->comment('有效投注額');
            $table->decimal('win', 18, 4)->default(0.0000)->comment('派彩金额');
            $table->decimal('win_lose', 18, 4)->default(0.0000)->comment('輸贏金额');
            $table->decimal('bonus', 18, 4)->default(0.0000)->comment('額外獎金/錦標賽獎金');
            $table->decimal('jackpot', 18, 4)->default(0.0000)->comment('彩池獎金');
            $table->decimal('rolling_chip', 18, 4)->default(0.0000)->comment('投注禮碼金額');
            // 狀態與時間欄位
            $table->timestamp('bet_time')->comment('投注時間');
            $table->timestamp('payout_time')->nullable()->comment('結算時間');
            $table->tinyInteger('status')->default(0)->comment('狀態 0:建立注單,1:進行中,2:退款,3:已結算');
            $table->timestamps();

            // 索引
            $table->index('vendor_info_id');
            $table->index('member_id');
            $table->index('username');
            $table->index('bet_id');
            $table->unique('reference_no');
            $table->index('game_code');
            $table->index('bet_time');
            $table->index('payout_time');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('ppsw_bet_records');
    }
};

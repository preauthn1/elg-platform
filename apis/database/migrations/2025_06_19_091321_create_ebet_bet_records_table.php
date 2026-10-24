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
        SchemaMonitor::create('ebet_bet_records', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->string('username', 191)->index()->comment('用戶名');
            $table->string('userId', 191)->comment('用戶獨立ID');
            $table->string('gameType', 191)->index()->comment('遊戲類型');
            $table->string('gameName', 191)->comment('遊戲名稱');
            $table->string('betHistoryId', 191)->unique()->comment('下注記錄ID,唯一ID');
            $table->decimal('bet', 18, 4)->default(0)->comment('投注額');
            $table->decimal('validBet', 18, 4)->default(0)->comment('有效投注額');
            $table->decimal('payout', 18, 4)->default(0)->comment('派彩');
            $table->decimal('balance', 18, 4)->default(0)->comment('盈餘');
            $table->decimal('payoutWithoutholding', 18, 4)->default(0)->comment('單純派彩金額 若沒有預扣金額，參數值會和payout相同 若有預扣金額，計算公式為payout – niuniuWithholdingtotal，其參數值大於0是玩家贏錢，等於0是玩家輸錢');
            $table->decimal('niuniuWithHoldingTotal', 18, 4)->default(0)->comment('牛牛預扣加總');
            $table->string('betMap', 191)->nullable()->comment('下注項目');
            $table->string('roundNo', 191)->nullable()->comment('牌局號');
            $table->text('payoutDetail')->nullable()->comment('派彩詳情');
            $table->text('judgeResult')->nullable()->comment('開牌結果');
            $table->text('playerCards')->nullable()->comment('閒家牌型');
            $table->integer('playerResult')->nullable()->comment('閒家結果');
            $table->text('bankerCards')->nullable()->comment('庄家牌型');
            $table->integer('bankerResult')->nullable()->comment('庄家結果');
            $table->text('allDices')->nullable()->comment('骰寶所有骰子');
            $table->integer('dragonCard')->nullable()->comment('龍虎，龍的牌型');
            $table->integer('tigerCard')->nullable()->comment('龍虎，虎的牌型');
            $table->integer('number')->nullable()->comment('輪盤遊戲，結果數字');
            $table->text('niuniuResult')->nullable()->comment('牛牛牌型比較結果');
            $table->text('niuniuWithHoldingDetail')->nullable()->comment('牛牛預扣betType細節參數');
            $table->integer('createTime')->nullable()->comment('開始時間');
            $table->dateTime('utc_create_time')->nullable()->comment('utc開始時間');
            $table->integer('payoutTime')->nullable()->comment('結算時間');
            $table->dateTime('utc_payout_time')->nullable()->index()->comment('utc結算時間');
            $table->integer('platform')->nullable()->comment('玩家遊戲平台,-1:other,0:andorid,1:iOS,2:Mobile H5,3:PC H5');
            $table->text('info')->nullable();
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('ebet_bet_records');
    }
};

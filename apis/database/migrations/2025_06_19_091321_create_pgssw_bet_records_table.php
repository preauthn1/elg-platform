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
        SchemaMonitor::create('pgssw_bet_records', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('vendor_info_id')->default(0)->index()->comment('遊戲商設定編號');
            $table->string('bet_id', 191)->default('0')->unique()->comment('注单唯一Id, ThemeId');
            $table->string('session_token', 50)->comment('訂單號的session_token');
            $table->string('username', 30)->index()->comment('玩家帳號');
            $table->string('game_code', 191)->index()->comment('游戏ID');
            $table->dateTime('bet_time')->index()->comment('投注時間(utc)');
            $table->dateTime('payout_time')->comment('派彩時間(utc)');
            $table->dateTime('settlement_time')->useCurrent()->index()->comment('結算時間');
            $table->decimal('bet', 18, 4)->default(0)->comment('下注金额');
            $table->decimal('win', 18, 4)->default(0)->comment('派彩金额');
            $table->decimal('win_lose', 18, 4)->default(0)->comment('輸贏金额');
            $table->decimal('rolling_chip', 18, 4)->default(0)->comment('禮碼金額');
            $table->tinyInteger('status')->default(0)->comment('狀態 0:未結算,1:已結算');
            $table->text('info')->comment('response data');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('pgssw_bet_records');
    }
};

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
        SchemaMonitor::create('ag_bet_records', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->string('data_type', 191)->comment('下注記錄詳情');
            $table->string('bill_no', 191)->unique()->comment('注單流水號');
            $table->string('player_name', 191)->index()->comment('玩家帳號');
            $table->string('agent_code', 191)->comment('代理商編號');
            $table->string('game_code', 191)->index()->comment('遊戲局號');
            $table->decimal('net_amount', 18, 4)->default(0)->comment('玩家輸贏金額');
            $table->dateTime('bet_time')->index()->comment('投注時間');
            $table->dateTime('utc_bet_time')->nullable()->index()->comment('時區+0的投注時間(AG原始的時區為-4)');
            $table->string('game_type', 191)->nullable()->comment('遊戲代碼');
            $table->decimal('bet_amount', 18, 4)->default(0)->comment('玩家投注金額');
            $table->decimal('valid_bet_amount', 18, 4)->default(0)->comment('有效投注金額');
            $table->integer('flag')->index()->comment('結算狀態0:未結算,1:已結算,2:下注中,4:撤單套現,-8:取消指定局注單,-9:取消指定注單');
            $table->integer('play_type')->comment('遊戲玩法');
            $table->string('currency', 10)->nullable()->comment('貨幣類型');
            $table->string('table_code', 191)->comment('桌子編號');
            $table->string('login_ip', 20)->comment('玩家IP');
            $table->string('recalcu_time', 20)->comment('注單重新派彩時間');
            $table->string('utc_recalcu_time', 20)->comment('結算時間(UTC時區)');
            $table->string('platform_type', 32)->index()->comment('平台類型');
            $table->text('remark')->comment('額外訊息');
            $table->string('round', 32)->comment('平台內大廳類型');
            $table->string('result', 191)->comment('結果');
            $table->decimal('before_credit', 10)->comment('下注前餘額');
            $table->integer('device_type')->comment('設備類型');
            $table->integer('game_category')->comment('0:非電子桌面遊戲,1:電子桌面遊戲');
            $table->text('cancel_reason')->comment('取消原因');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('ag_bet_records');
    }
};

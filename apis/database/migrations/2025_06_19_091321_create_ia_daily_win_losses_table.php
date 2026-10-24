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
        SchemaMonitor::create('ia_daily_win_losses', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->string('rowID', 191)->comment('下注唯一id');
            $table->string('game_id', 191)->comment('遊戲id');
            $table->string('points_id', 191)->nullable();
            $table->decimal('bonus', 18, 4)->comment('派獎金額');
            $table->string('winner', 191);
            $table->decimal('amount', 18, 4)->comment('下注總金額');
            $table->string('create_time', 191)->comment('下注時間');
            $table->string('update_time', 191)->comment('最後更新時間');
            $table->string('bonus_time', 191)->nullable()->comment('派獎時間');
            $table->string('cancel_time', 191)->nullable()->comment('註單取消時間');
            $table->tinyInteger('is_cancel')->comment('註單是否取消 1-取消 0-未取消');
            $table->tinyInteger('is_getprize')->index()->comment('註單是否結算 1-結算 0-未結算');
            $table->tinyInteger('prize_status')->comment('註單輸贏狀態 1-贏 2-輸');
            $table->string('userip', 191)->comment('下注IP');
            $table->string('cdnip', 191)->comment('下注cdnIP');
            $table->tinyInteger('money_type')->comment('金錢類型 1-現金 2-虛擬幣');
            $table->integer('is_change')->nullable()->comment('註單是否換隊 1-已換 0-沒換');
            $table->string('game_type_id', 191)->comment('游戲類型');
            $table->string('odds', 191)->comment('下注時的賠率');
            $table->string('client_type', 191)->comment('客戶使用的客戶端 1-pc 2-wap 3-app');
            $table->string('category_id', 191)->comment('下注的註單類型 1-彩池盤 2-固賠 3-滾球');
            $table->string('desc', 191)->comment('第一局輸贏');
            $table->string('cancel_user', 191)->nullable();
            $table->string('currency_id', 191)->comment('幣種');
            $table->string('exchange_rate', 191)->comment('幣種對人民幣匯率');
            $table->string('show_scale', 191)->comment('幣種顯示縮放比例');
            $table->string('settlement_odds', 191)->nullable()->comment('結算賠率');
            $table->string('prize_winner', 191)->nullable();
            $table->string('third_mark_id', 191);
            $table->string('receive_status', 191);
            $table->string('is_champion', 191);
            $table->string('game_name', 191)->comment('遊戲名稱');
            $table->string('team_name_1', 191)->comment('左邊隊伍名稱');
            $table->string('team_name_2', 191)->comment('右邊隊伍名稱');
            $table->string('team_info_desc', 191)->comment('相關隊伍名稱(冠軍盤的時候才有數據)');
            $table->string('category_type', 191)->comment('盤口類型 1-普通 2-冠軍');
            $table->string('username', 191)->index()->comment('用戶名');
            $table->string('team_points', 191)->nullable()->comment('隊伍賠率');
            $table->string('team_name', 191)->nullable()->comment('下注的隊伍名稱');
            $table->string('event_id', 191)->comment('賽事id');
            $table->string('user_points', 191)->nullable()->comment('用戶賠率');
            $table->decimal('win_lose', 18, 4)->comment('用戶贏或者輸的金額');
            $table->string('order_id', 191)->comment('注單號');
            $table->string('game_start_time', 191)->comment('游戲開始時間');
            $table->string('original_utc_start_time', 191)->nullable()->comment('子盤口開始時間');
            $table->string('event_name', 191)->comment('賽事名稱');
            $table->string('utc_update_time', 191)->index()->comment('最後更新時間(UTC+0)');
            $table->integer('updated')->default(0)->comment('註單是否更新過');
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
        SchemaMonitor::dropIfExists('ia_daily_win_losses');
    }
};

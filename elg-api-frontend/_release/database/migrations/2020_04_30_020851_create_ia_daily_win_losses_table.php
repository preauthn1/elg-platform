<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateIaDailyWinLossesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('ia_daily_win_losses', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->string('rowID')->comment('下注唯一id');
            $table->string('game_id')->comment('遊戲id');
            $table->string('points_id')->comment('');
            $table->decimal('bonus', 13, 7)->comment('派獎金額');
            $table->string('winner')->comment('');
            $table->decimal('amount', 13, 7)->comment('下注總金額');
            $table->string('create_time')->comment('下注時間');
            $table->string('update_time')->comment('最後更新時間');
            $table->string('bonus_time')->comment('派獎時間');
            $table->string('cancel_time')->comment('註單取消時間');
            $table->tinyInteger('is_cancel')->comment('註單是否取消 1-取消 0-未取消');
            $table->tinyInteger('is_getprize')->comment('註單是否結算 1-結算 0-未結算');
            $table->tinyInteger('prize_status')->comment('註單輸贏狀態 1-贏 2-輸');
            $table->string('userip')->comment('下注IP');
            $table->string('cdnip')->comment('下注cdnIP');
            $table->tinyInteger('money_type')->comment('金錢類型 1-現金 2-虛擬幣');
            $table->tinyInteger('is_change')->comment('註單是否換隊 1-已換 0-沒換');
            $table->string('game_type_id')->comment('游戲類型');
            $table->string('odds')->comment('下注時的賠率');
            $table->string('client_type')->comment('客戶使用的客戶端 1-pc 2-wap 3-app');
            $table->string('category_id')->comment('下注的註單類型 1-彩池盤 2-固賠 3-滾球');
            $table->string('desc')->comment('第一局輸贏');
            $table->string('cancel_user')->comment('');
            $table->string('currency_id')->comment('幣種');
            $table->string('exchange_rate')->comment('幣種對人民幣匯率');
            $table->string('show_scale')->comment('幣種顯示縮放比例');
            $table->string('settlement_odds')->comment('結算賠率');
            $table->string('prize_winner')->comment('');
            $table->string('third_mark_id')->comment('');
            $table->string('receive_status')->comment('');
            $table->string('is_champion')->comment('');
            $table->string('game_name')->comment('遊戲名稱');
            $table->string('team_name_1')->comment('左邊隊伍名稱');
            $table->string('team_name_2')->comment('右邊隊伍名稱');
            $table->string('team_info_desc')->comment('相關隊伍名稱(冠軍盤的時候才有數據)');
            $table->string('category_type')->comment('盤口類型 1-普通 2-冠軍');
            $table->string('username')->comment('用戶名');
            $table->string('team_points')->comment('隊伍賠率');
            $table->string('team_name')->comment('下注的隊伍名稱');
            $table->string('event_id')->comment('賽事id');
            $table->string('user_points')->comment('用戶賠率');
            $table->decimal('win_lose', 13, 7)->comment('用戶贏或者輸的金額');
            $table->string('order_id')->comment('注單號');
            $table->string('game_start_time')->comment('游戲開始時間');
            $table->string('point_start_time')->comment('子盤口開始時間');
            $table->string('event_name')->comment('賽事名稱');
            $table->string('utc_update_time')->comment('最後更新時間(UTC+0)');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('ia_daily_win_losses');
    }
}

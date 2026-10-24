<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('rectanglesw_accounts', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('vendor_info_id')->default(0)->comment('遊戲商設定編號');
            $table->integer('member_id')->default(0)->comment('會員系統編號');
            $table->string('username', 100)->comment('玩家帳號');
            $table->string('session_token', 100)->nullable()->comment('遊戲 session token');
            $table->tinyInteger('status')->default(1)->comment('狀態 0:關閉,1:正常');
            $table->timestamps();

            $table->index(['vendor_info_id', 'member_id', 'status'], 'idx_rg_bind_lookup');
            $table->index(['vendor_info_id', 'status', 'member_id', 'id'], 'idx_rg_pool_pick');
            $table->index('session_token', 'idx_rg_session_token');
        });

        SchemaMonitor::create('rectanglesw_bet_records', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('vendor_info_id')->default(0)->comment('遊戲商設定編號');
            $table->integer('member_id')->default(0)->comment('會員系統編號');
            $table->string('username', 100)->comment('玩家帳號');
            $table->string('bet_id', 100)->comment('注單 ID');
            $table->string('reference_no', 100)->unique()->comment('三方交易 ID (transactionId)，用於冪等');
            $table->string('game_code', 100)->comment('遊戲代碼');
            $table->string('bet_type', 50)->default('NORMAL')->comment('投注類型');
            $table->decimal('bet', 18, 4)->default(0)->comment('投注金額');
            $table->decimal('valid_bet', 18, 4)->default(0)->comment('有效投注');
            $table->decimal('win', 18, 4)->default(0)->comment('派彩金額');
            $table->decimal('win_lose', 18, 4)->default(0)->comment('輸贏金額');
            $table->decimal('bonus', 18, 4)->default(0)->comment('活動獎金');
            $table->decimal('jackpot', 18, 4)->default(0)->comment('Jackpot 獎金');
            $table->decimal('rolling_chip', 18, 4)->default(0)->comment('禮碼金額');
            $table->tinyInteger('is_end_round')->default(1)->comment('是否為該局結束');
            $table->dateTime('bet_time')->nullable()->comment('投注時間');
            $table->dateTime('payout_time')->nullable()->comment('派彩時間');
            $table->text('raw_data')->nullable()->comment('原始請求資料');
            $table->timestamps();

            $table->index('vendor_info_id');
            $table->index('member_id');
            $table->index('username');
            $table->index('bet_id');
            $table->index('game_code');
            $table->index('bet_time');
            $table->index('payout_time');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('rectanglesw_bet_records');
        SchemaMonitor::dropIfExists('rectanglesw_accounts');
    }
};

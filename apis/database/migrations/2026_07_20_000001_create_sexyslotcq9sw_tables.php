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
        SchemaMonitor::create('sexyslotcq9sw_accounts', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('vendor_info_id')->default(0)->comment('遊戲商設定編號');
            $table->string('username')->unique()->comment('玩家帳號');
            $table->integer('member_id')->default(0)->index()->comment('會員系統編號');
            $table->tinyInteger('status')->default(1)->comment('狀態 0:關閉,1:正常');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('管理員編號');
        });

        SchemaMonitor::create('sexyslotcq9sw_bet_records', function (Blueprint $table) {
            $table->increments('id');
            $table->string('bet_id')->comment('注單id, request roundid');
            $table->string('username')->index()->comment('玩家帳號');
            $table->string('game_code')->comment('遊戲編號');
            $table->integer('type')->comment('該回合最後的事件 1:Bet, 2:EndRound, 3:Rollout, 4:Rollin, 5:Refund, 6:Debit, 7:Credit, 8:TakeAll, 9:Payoff');
            $table->decimal('bet', 18, 4)->default(0)->comment('下注金額');
            $table->decimal('valid_bet', 18, 4)->default(0)->comment('有效下注金額');
            $table->decimal('win_lose', 18, 4)->default(0)->comment('輸贏金額');
            $table->decimal('rolling_chip', 18, 4)->default(0)->comment('使用禮碼');
            $table->decimal('bonus', 18, 4)->default(0)->comment('bonus');
            $table->decimal('jackpot', 18, 4)->default(0)->comment('jackpot');
            $table->dateTime('bet_time')->nullable()->index()->comment('下注時間');
            $table->dateTime('payout_time')->nullable()->index()->comment('報表結算時間');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');

            $table->unique(['bet_id'], 'unique_index');
        });
        SchemaMonitor::create('sexyslotcq9sw_deal_records', function (Blueprint $table) {
            $table->increments('id');
            $table->string('bet_id')->index()->comment('注單id, request roundid');
            $table->string('mtcode')->index()->comment('mtcode');
            $table->decimal('amount', 18, 4)->default(0)->comment('金額');
            $table->string('username')->index()->comment('玩家帳號');
            $table->decimal('balance', 18, 4)->default(0)->comment('變動後錢包餘額');
            $table->string('game_hall')->comment('遊戲廠商');
            $table->string('game_code')->comment('遊戲編號');
            $table->integer('type')->comment('這筆 mtcode 自己的事件 1:Bet, 2:EndRound, 3:Rollout, 4:Rollin, 5:Refund, 6:Debit, 7:Credit, 8:TakeAll, 9:Payoff');
            $table->boolean('is_refund')->default(false)->comment('是否已被退款（refund 冪等判斷，不覆蓋 type）');
            $table->dateTime('event_time')->index()->comment('事件時間');
            $table->json('info')->nullable()->comment('原始 callback payload');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');

            $table->unique(['mtcode'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('sexyslotcq9sw_bet_records');
        SchemaMonitor::dropIfExists('sexyslotcq9sw_accounts');
        SchemaMonitor::dropIfExists('sexyslotcq9sw_deal_records');
    }
};

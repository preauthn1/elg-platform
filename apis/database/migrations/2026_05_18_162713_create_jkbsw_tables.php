<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('jkbsw_accounts', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('vendor_info_id')->default(0)->comment('遊戲商設定編號');
            $table->integer('member_id')->default(0)->comment('會員系統編號');
            $table->string('username', 100)->comment('Joker playerID 含 prefix');
            $table->string('password', 100)->nullable()->comment('Register Player 設定的密碼（OP/客服登入 Joker portal 用）');
            $table->tinyInteger('status')->default(1)->comment('狀態 0:關閉,1:正常');
            $table->timestamps();

            $table->index(['vendor_info_id', 'member_id', 'status'], 'idx_jkbsw_bind_lookup');
            $table->index(['vendor_info_id', 'status', 'member_id', 'id'], 'idx_jkbsw_pool_pick');
            $table->unique('username', 'uniq_jkbsw_username');
        });

        SchemaMonitor::create('jkbsw_bet_records', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('vendor_info_id')->default(0)->comment('遊戲商設定編號');
            $table->integer('member_id')->default(0)->comment('會員系統編號');
            $table->string('username', 100)->comment('Joker playerID');
            $table->string('bet_id', 100)->unique()->comment('Joker roundID（同 round 多筆 transaction 共用）');
            $table->string('game_code', 50)->comment('桌台代碼 tableCode');

            $table->decimal('bet', 18, 4)->default(0)->comment('累計投注金額（同 round 多筆 action=2 累加）');
            $table->decimal('valid_bet', 18, 4)->default(0)->comment('累計有效投注 validTurnover');
            $table->decimal('win', 18, 4)->default(0)->comment('累計派彩金額（同 round 多筆 action=3 累加）');
            $table->decimal('win_lose', 18, 4)->default(0)->comment('輸贏 = win - bet');
            $table->decimal('bonus', 18, 4)->default(0)->comment('活動獎金');
            $table->decimal('jackpot', 18, 4)->default(0)->comment('Jackpot 獎金');
            $table->decimal('rolling_chip', 18, 4)->default(0)->comment('禮碼金額');

            $table->dateTime('bet_time')->nullable()->comment('首次 action=2 寫入');
            $table->dateTime('payout_time')->nullable()->comment('最近一次 action=3 寫入');
            $table->tinyInteger('status')->default(0)->comment('狀態 0:建立,1:已結算,-1:已退款');
            $table->timestamps();

            $table->index('vendor_info_id');
            $table->index('member_id');
            $table->index('username');
            $table->index('game_code');
            $table->index('bet_time');
            $table->index('payout_time');
        });

        SchemaMonitor::create('jkbsw_deal_records', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('vendor_info_id')->default(0)->comment('遊戲商設定編號');
            $table->integer('member_id')->default(0)->comment('會員系統編號');
            $table->string('username', 100)->comment('Joker playerID');
            $table->unsignedInteger('bet_record_id')->nullable()->comment('FK jkbsw_bet_records.id；rollback 找不到時可 nullable');
            $table->string('bet_id', 100)->comment('Joker roundID');
            $table->string('reference_no', 100)->unique()->comment('Joker transactionNo — 每筆 callback 冪等鍵');
            $table->tinyInteger('action')->comment('Joker action: 2=Bet,3=Win,4=Refund,5=Rollback');
            $table->decimal('amount', 18, 4)->default(0)->comment('本次金額 (正數)');
            $table->decimal('balance_before', 18, 4)->nullable()->comment('扣/加前餘額');
            $table->decimal('balance_after', 18, 4)->nullable()->comment('扣/加後餘額');
            $table->json('info')->nullable()->comment('原始 callback payload');
            $table->timestamps();

            $table->index('vendor_info_id');
            $table->index('member_id');
            $table->index('username');
            $table->index('bet_id');
            $table->index('bet_record_id');
            $table->index('action');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('jkbsw_deal_records');
        SchemaMonitor::dropIfExists('jkbsw_bet_records');
        SchemaMonitor::dropIfExists('jkbsw_accounts');
    }
};

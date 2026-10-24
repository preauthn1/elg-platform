<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('gmsw_accounts', function (Blueprint $table) {
            $table->vendorAccountColumns(
                usernameLength: 100,
                uniqueUsername: true,
                withSessionToken: [REDACTED],
            );
        });

        SchemaMonitor::create('gmsw_bet_records', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('vendor_info_id')->default(0)->comment('遊戲商設定編號');
            $table->integer('member_id')->default(0)->comment('會員系統編號');
            $table->string('username', 100)->comment('GM uname');
            $table->string('bet_id', 100)->unique()->comment('GM session_id（round 唯一鍵）');
            $table->string('game_code', 50)->default('')->comment('遊戲代碼');

            $table->decimal('bet', 18, 4)->default(0)->comment('累計投注（type=1 累加）');
            $table->decimal('valid_bet', 18, 4)->default(0)->comment('累計有效投注');
            $table->decimal('win', 18, 4)->default(0)->comment('累計派彩（type=3 累加）');
            $table->decimal('win_lose', 18, 4)->default(0)->comment('輸贏 = win - bet');
            $table->decimal('bonus', 18, 4)->default(0)->comment('活動獎金');
            $table->decimal('jackpot', 18, 4)->default(0)->comment('Jackpot 獎金');
            $table->decimal('rolling_chip', 18, 4)->default(0)->comment('禮碼金額');

            $table->dateTime('bet_time')->nullable()->comment('首次 type=1 投注時間');
            $table->dateTime('payout_time')->nullable()->comment('最近一次 type=3 派彩時間');
            $table->tinyInteger('status')->default(0)->comment('0:進行中, 1:已結算（type=4）, -1:已取消（type=2）');
            $table->text('info')->nullable();
            $table->timestamps();

            $table->index('vendor_info_id');
            $table->index('member_id');
            $table->index('username');
            $table->index('game_code');
            $table->index('bet_time');
            $table->index('payout_time');
            $table->index('status');
        });

        SchemaMonitor::create('gmsw_deal_records', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('vendor_info_id')->default(0)->comment('遊戲商設定編號');
            $table->integer('member_id')->default(0)->comment('會員系統編號');
            $table->string('username', 100)->comment('GM uname');
            $table->unsignedInteger('bet_record_id')->nullable()->comment('FK gmsw_bet_records.id；type=5/6 無對應 BR 時可 nullable');
            $table->string('bet_id', 100)->comment('GM session_id（round ID）');
            $table->string('reference_no', 100)->unique()->comment('GM order_id — 每筆 callback 冪等鍵');
            $table->tinyInteger('action')->comment('GM type: 1=Bet, 2=Cancel, 3=Win, 4=EndRound, 5=LuckwinBonus, 6=FutureFee');
            $table->decimal('amount', 18, 4)->default(0)->comment('本次金額（正數，方向依 action 判斷）');
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
        SchemaMonitor::dropIfExists('gmsw_deal_records');
        SchemaMonitor::dropIfExists('gmsw_bet_records');
        SchemaMonitor::dropIfExists('gmsw_accounts');
    }
};

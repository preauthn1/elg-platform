<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('rgsw_accounts', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('vendor_info_id')->default(0)->comment('遊戲商設定編號');
            $table->integer('member_id')->default(0)->comment('會員系統編號');
            $table->string('username', 100)->unique()->comment('玩家帳號 (RG前綴+會員帳號)');
            $table->tinyInteger('status')->default(1)->comment('狀態 0:關閉,1:正常');
            $table->timestamps();

            $table->index(['vendor_info_id', 'member_id', 'status'], 'idx_rgsw_accounts_bind_lookup');
            $table->index(['vendor_info_id', 'status', 'member_id', 'id'], 'idx_rgsw_accounts_pool_pick');
        });

        SchemaMonitor::create('rgsw_bet_records', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('vendor_info_id')->default(0)->comment('遊戲商設定編號');
            $table->integer('member_id')->default(0)->comment('會員系統編號');
            $table->string('username', 100)->comment('玩家帳號');
            $table->string('bet_id', 100)->comment('注單編號（同回合可多筆）');
            $table->string('reference_no', 100)->unique()->comment('交易編號（唯一）');
            $table->string('game_code', 100)->default('')->comment('遊戲代碼');
            $table->string('game_type', 20)->default('')->comment('遊戲類型 slot/fishing/chess');
            $table->tinyInteger('status')->comment('1:BET 2:PAYOUT 3:ENDROUND 4:BETPAYOUT 5:REFUND 6:DEBIT 7:CREDIT 8:SUMBET');
            $table->decimal('bet', 18, 4)->default(0)->comment('下注金額');
            $table->decimal('valid_bet', 18, 4)->default(0)->comment('有效投注');
            $table->decimal('win_lose', 18, 4)->default(0)->comment('輸贏金額');
            $table->decimal('win', 18, 4)->default(0)->comment('派彩金額');
            $table->decimal('bonus', 18, 4)->default(0)->comment('活動獎金');
            $table->decimal('jackpot', 18, 4)->default(0)->comment('彩金');
            $table->decimal('rebate', 18, 4)->default(0)->comment('返水');
            $table->decimal('rolling_chip', 18, 4)->default(0)->comment('使用禮碼');
            $table->dateTime('bet_time')->nullable()->comment('下注時間');
            $table->dateTime('payout_time')->nullable()->comment('派彩時間');
            $table->text('info')->nullable()->comment('原始請求資訊');
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
        SchemaMonitor::dropIfExists('rgsw_bet_records');
        SchemaMonitor::dropIfExists('rgsw_accounts');
    }
};

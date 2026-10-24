<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('megah5sw_bet_records', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('vendor_info_id')->default(0)->index()->comment('遊戲商設定編號');
            $table->integer('member_id')->default(0)->index()->comment('會員系統編號');
            $table->string('type', 1)->default('N')->index()->comment('N:normal, B:bonus, J:jackpot');
            $table->string('round_id', 30)->nullable()->index()->comment('遊戲回合Id（RoundId）');
            $table->string('bet_id', 50)->index()->comment('注單唯一Id（N:BetId, B:BonusId, J:JackpotId）');
            $table->string('reference_no', 30)->default('0')->unique()->comment('三方訂單號（N:ResultId, B/J:TranId）');
            $table->string('username', 50)->index()->comment('玩家帳號');
            $table->string('game_code', 50)->index()->comment('遊戲代碼');
            $table->timestamp('bet_time')->index()->comment('投注時間(UTC)');
            $table->timestamp('payout_time')->nullable()->index()->comment('派彩時間(UTC)');
            $table->decimal('bet', 18, 4)->default(0)->comment('下注金額');
            $table->decimal('valid_bet', 18, 4)->nullable()->comment('有效投注');
            $table->decimal('win', 18, 4)->default(0)->comment('派彩金額');
            $table->decimal('win_lose', 18, 4)->default(0)->comment('輸贏金額');
            $table->decimal('rolling_chip', 18, 4)->default(0)->comment('禮碼金額');
            $table->decimal('bonus', 18, 4)->nullable()->default(0)->comment('活動獎金');
            $table->decimal('jackpot', 18, 4)->nullable()->default(0)->comment('Jackpot 獎金');
            $table->tinyInteger('status')->default(0)->index()->comment('注單狀態 0:建立中 1:結算/派彩 2:回滾');
            $table->text('info')->comment('原始請求資料');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');

            $table->unique(['type', 'bet_id', 'payout_time'], 'unique_type_bet_payout');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('megah5sw_bet_records');
    }
};

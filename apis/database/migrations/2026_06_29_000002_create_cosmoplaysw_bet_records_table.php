<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('cosmoplaysw_bet_records', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('vendor_info_id')->default(0)->comment('遊戲商設定編號');
            $table->integer('member_id')->default(0)->index()->comment('會員系統編號');
            $table->string('bet_id', 50)->unique()->comment('注單唯一識別 SpinID');
            $table->string('round_id', 50)->index()->comment('遊戲回合 RoundID');
            $table->string('username', 100)->index()->comment('玩家帳號');
            $table->string('game_code', 50)->comment('遊戲代碼 GameID');
            $table->dateTime('bet_time')->index()->comment('投注時間');
            $table->dateTime('payout_time')->nullable()->index()->comment('派彩時間');
            $table->decimal('bet', 18, 4)->default(0)->comment('下注金額');
            $table->decimal('valid_bet', 18, 4)->default(0)->comment('有效投注');
            $table->decimal('win', 18, 4)->default(0)->comment('派彩金額');
            $table->decimal('win_lose', 18, 4)->default(0)->comment('輸贏金額');
            $table->decimal('rolling_chip', 18, 4)->default(0)->comment('禮碼金額');
            $table->tinyInteger('is_round_end')->default(0)->comment('是否為該回合最後一次 Spin');
            $table->tinyInteger('status')->default(0)->comment('注單狀態 0:投注中 1:已結算 2:已取消');
            $table->text('info')->comment('原始請求資料');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('cosmoplaysw_bet_records');
    }
};

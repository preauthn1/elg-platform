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
        SchemaMonitor::create('tthsw_bet_records', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('vendor_info_id')->default(0)->index()->comment('遊戲商設定編號');
            $table->string('username')->index()->comment('玩家帳號');
            $table->string('bet_id')->unique()->comment('注單唯一ID');
            $table->string('game_code')->index()->comment('遊戲代碼');
            $table->decimal('bet', 18, 4)->default(0)->comment('投注額');
            $table->decimal('valid_bet', 18, 4)->default(0)->comment('有效投注額');
            $table->decimal('win', 18, 4)->default(0)->comment('派彩金額');
            $table->decimal('win_lose', 18, 4)->default(0)->comment('輸贏金額');
            $table->decimal('bonus', 18, 4)->default(0)->comment('額外獎金');
            $table->decimal('jackpot', 18, 4)->default(0)->comment('彩池獎金');
            $table->decimal('rolling_chip', 18, 4)->default(0)->comment('投注禮碼金額');
            $table->decimal('reserve_amount', 18, 4)->default(0)->comment('預留金額');
            $table->decimal('reserve_used', 18, 4)->default(0)->comment('已使用的預留金額');
            $table->dateTime('bet_time')->index()->comment('投注時間');
            $table->dateTime('payout_time')->nullable()->index()->comment('結算時間');
            $table->tinyInteger('status')->default(0)->comment('狀態 0:建立注單,1:進行中,2:退款,3:已結算');
            $table->text('info')->nullable()->comment('Response data');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('tthsw_bet_records');
    }
};

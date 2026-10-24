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
        SchemaMonitor::create('boongosw_bet_records', function (Blueprint $table) {
            $table->increments('id');
            $table->string('bet_id', 200)->unique()->comment('交易 id');
            $table->integer('vendor_info_id')->index()->comment('遊戲商設定編號');
            $table->string('username', 200)->index()->comment('玩家帳號');
            $table->string('game_code', 200)->comment('遊戲編號');
            $table->decimal('bet', 18, 4)->default(0)->comment('下注金額');
            $table->decimal('valid_bet', 18, 4)->default(0)->comment('有效下注金額');
            $table->decimal('win_lose', 18, 4)->default(0)->comment('輸贏金額');
            $table->decimal('rolling_chips', 18, 4)->default(0)->comment('禮碼');
            $table->dateTime('bet_time')->comment('下注時間');
            $table->dateTime('payout_time')->index()->comment('派彩時間');
            $table->tinyInteger('status')->index()->comment('狀態;0:未結算,1:已結算');
            $table->integer('version')->comment('版本(API回傳用);0:投注時創建,n:後面更改了n次');
            $table->text('infos')->nullable()->comment('API回傳資料');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('boongosw_bet_records');
    }
};

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
        SchemaMonitor::create('astar_bet_records', function (Blueprint $table) {
            $table->increments('id');
            $table->string('bet_id', 191)->default('0')->unique()->comment('注單唯一Id, ThemeId');
            $table->string('vendor_username', 191)->index()->comment('遊戲商的玩家帳號名');
            $table->dateTime('bet_time')->index()->comment('投注時間(UTC)');
            $table->decimal('bet', 18, 4)->default(0)->comment('下注金額');
            $table->decimal('valid_bet', 18, 4)->default(0)->comment('有效投注金額');
            $table->decimal('win_lose', 18, 4)->default(0)->comment('輸贏金額');
            $table->dateTime('payout_time')->nullable()->index()->comment('派彩、結算時間(UTC)');
            $table->tinyInteger('status')->default(0)->index()->comment('狀態 0:未結算,1:已結算');
            $table->string('game_code', 191)->default('')->comment('遊戲 ID-依據遊戲商需求');
            $table->decimal('rolling_chip', 18, 4)->default(0)->comment('禮碼金額-依據遊戲商需求');
            $table->decimal('jackpot', 18, 4)->default(0)->comment('派彩金额-依據遊戲商需求');
            $table->string('session_token', 191)->default('')->index()->comment('訪問訂單的 Session Token 或 ID-依據遊戲商需求');
            $table->text('info')->comment('response data');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('astar_bet_records');
    }
};

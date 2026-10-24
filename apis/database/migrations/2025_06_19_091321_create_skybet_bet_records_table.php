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
        SchemaMonitor::create('skybet_bet_records', function (Blueprint $table) {
            $table->increments('id');
            $table->string('bet_id', 191)->default('0')->unique()->comment('注单唯一Id, ThemeId');
            $table->string('username', 30)->comment('玩家帳號');
            $table->string('game_code', 191)->comment('游戏ID');
            $table->dateTime('bet_time')->comment('派彩時間(utc)');
            $table->dateTime('created_time')->comment('押注時間(utc)');
            $table->integer('bet_status');
            $table->integer('settlement_status');
            $table->decimal('bet', 18, 4)->default(0)->comment('下注金额; BetCoin');
            $table->decimal('valid_bet', 18, 4)->default(0)->comment('有效投注; AvailTotalBet');
            $table->decimal('win', 18, 4)->default(0)->comment('贏得金额; WinAmount');
            $table->decimal('win_lose', 18, 4)->default(0)->comment('輸贏金额; WinAmount - BetCoin');
            $table->string('info', 500)->comment('response data');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('skybet_bet_records');
    }
};

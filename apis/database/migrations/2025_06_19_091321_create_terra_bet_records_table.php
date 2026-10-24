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
        SchemaMonitor::create('terra_bet_records', function (Blueprint $table) {
            $table->increments('id');
            $table->bigInteger('bet_id')->default(0)->unique()->comment('注单唯一Id, ThemeId');
            $table->string('username', 30)->index()->comment('玩家帳號');
            $table->string('game_code', 191)->index()->comment('游戏ID');
            $table->dateTime('bet_time')->index()->comment('押注時間(utc)');
            $table->decimal('bet', 18, 4)->default(0)->comment('下注金额; BetCoin');
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
        SchemaMonitor::dropIfExists('terra_bet_records');
    }
};

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
        SchemaMonitor::create('epic_sw_bet_records', function (Blueprint $table) {
            $table->increments('id');
            $table->string('type', 1)->default('N')->index()->comment('N:normal, B:bonus, J:jackpot');
            $table->string('bet_id', 40)->default('0')->index()->comment('注单唯一Id, ThemeId');
            $table->bigInteger('result_id')->default(0)->index();
            $table->string('username', 30)->index()->comment('玩家帳號');
            $table->string('game_code', 50)->index()->comment('游戏ID');
            $table->dateTime('bet_time')->comment('投注時間(utc)');
            $table->dateTime('payout_time')->index()->comment('派彩時間(UTC)');
            $table->decimal('bet', 18, 4)->default(0)->comment('下注金额');
            $table->decimal('valid_bet', 18, 4)->nullable()->default(0)->comment('有效投注');
            $table->decimal('win_lose', 18, 4)->default(0)->comment('輸贏金额');
            $table->decimal('rolling_chip', 18, 4)->default(0)->comment('禮碼金额');
            $table->decimal('bonus', 18, 4)->nullable()->default(0)->comment('bonus/jackpot');
            $table->integer('status')->default(1)->index()->comment('注單狀態 0: 取消 1:成功');
            $table->text('info')->comment('response data');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');

            $table->unique(['type', 'bet_id', 'payout_time'], 'unique_index');
            $table->primary(['id', 'payout_time']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('epic_sw_bet_records');
    }
};

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
        SchemaMonitor::create('event_uuslotsw_bet_records', function (Blueprint $table) {
            $table->increments('id');
            $table->string('bet_id', 200)->unique()->comment('交易 id');
            $table->string('reference_bet_id', 200)->nullable()->unique()->comment('game result trnas id');
            $table->string('username', 200)->index()->comment('玩家帳號');
            $table->string('game_code', 200)->comment('遊戲編號');
            $table->enum('status', ['bet', 'result', 'rollback', 'bonus', 'jackpot'])->index()->comment('bet: 下注, result: 派彩, rollback: 取消下注, bonus: 紅包, jackpot: 大獎');
            $table->decimal('bet', 18, 4)->default(0)->comment('下注金額');
            $table->decimal('valid_bet', 18, 4)->default(0)->comment('合法下注金額');
            $table->decimal('payout', 18, 4)->default(0)->comment('贏得金額');
            $table->decimal('win_lose', 18, 4)->default(0)->comment('輸贏金額');
            $table->dateTime('bet_time')->comment('下注時間');
            $table->dateTime('payout_time')->index()->comment('派彩/取消/bonus時間');
            $table->json('infos')->nullable()->comment('詳細資訊');
            $table->json('result_infos')->nullable()->comment('game result 詳細資訊');
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('event_uuslotsw_bet_records');
    }
};

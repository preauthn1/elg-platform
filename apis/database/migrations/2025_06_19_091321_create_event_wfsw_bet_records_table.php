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
        SchemaMonitor::create('event_wfsw_bet_records', function (Blueprint $table) {
            $table->increments('id');
            $table->string('bet_id', 200)->index()->comment('交易 id, gameRoundId from request');
            $table->string('result_id', 191)->nullable()->index()->comment('交易 id, gameRoundId from request');
            $table->string('username', 200)->index()->comment('玩家帳號');
            $table->string('game_code', 200)->index()->comment('遊戲編號, gameId from request');
            $table->integer('status')->comment('1:bet,2:result,3:rollback,4:bouns,5:jackpot');
            $table->decimal('bet', 18, 4)->default(0)->comment('下注金額');
            $table->decimal('valid_bet', 18, 4)->default(0)->comment('真實下注金額');
            $table->decimal('payout', 18, 4)->default(0)->comment('贏得金額');
            $table->decimal('win_lose', 18, 4)->default(0)->comment('輸贏金額');
            $table->decimal('rolling_chips', 18, 4)->default(0)->comment('使用禮碼');
            $table->dateTime('bet_time')->index()->comment('下注時間');
            $table->dateTime('payout_time')->index()->comment('派彩/取消/bonus時間');
            $table->json('infos')->nullable()->comment('詳細資訊');
            $table->json('result_infos');
            $table->timestamps();

            $table->unique(['bet_id'], 'unique_index');

            $table->foreign(['username'])->references(['username'])->on('event_wfsw_accounts')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('event_wfsw_bet_records');
    }
};

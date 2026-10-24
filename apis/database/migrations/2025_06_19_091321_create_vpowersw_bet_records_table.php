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
        SchemaMonitor::create('vpowersw_bet_records', function (Blueprint $table) {
            $table->increments('id');
            $table->string('bet_id', 200)->unique()->comment('交易 id, gameRoundId from request');
            $table->string('reference_bet_id', 200)->nullable()->comment('關聯交易 id');
            $table->string('username', 200)->index()->comment('玩家帳號');
            $table->string('game_code', 200)->comment('遊戲編號, gameId from request');
            $table->string('status', 1)->comment('0: 回合未完成, 1: 回合完成');
            $table->string('type', 1)->comment('類別');
            $table->decimal('bet', 18, 4)->default(0)->comment('下注金額');
            $table->decimal('valid_bet', 18, 4)->default(0)->comment('真實下注金額');
            $table->decimal('win_lose', 18, 4)->default(0)->comment('輸贏金額');
            $table->decimal('rolling_chips', 18, 4)->default(0)->comment('使用禮碼');
            $table->dateTime('bet_time')->comment('下注時間');
            $table->dateTime('payout_time')->comment('派彩/取消/bonus時間');
            $table->json('infos')->nullable()->comment('詳細資訊');
            $table->timestamps();

            $table->foreign(['username'])->references(['username'])->on('vpowersw_accounts')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('vpowersw_bet_records');
    }
};

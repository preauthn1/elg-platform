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
        SchemaMonitor::create('motivationsw_bet_records', function (Blueprint $table) {
            $table->increments('id');
            $table->string('bet_id', 200)->unique('unique_index')->comment('注單id');
            $table->string('username', 200)->index()->comment('玩家帳號');
            $table->string('game_code', 200)->comment('遊戲編號, gameId from request');
            $table->string('mtcode', 200)->index()->comment('mtcode');
            $table->integer('status')->comment('1:Bet, 2:EndRound, 3:Rollout, 4:Rollin, 5:Refund');
            $table->decimal('bet', 18, 4)->default(0)->comment('下注金額');
            $table->decimal('valid_bet', 18, 4)->default(0)->comment('有效下注金額');
            $table->decimal('win_lose', 18, 4)->default(0)->comment('輸贏金額');
            $table->decimal('rolling_chips', 18, 4)->default(0)->comment('使用禮碼');
            $table->dateTime('bet_time')->index()->comment('下注時間');
            $table->dateTime('result_date')->nullable()->index()->comment('算結果的日期時間');
            $table->json('infos')->nullable()->comment('詳細資訊');
            $table->json('result_infos')->nullable()->comment('result 詳細資訊');
            $table->timestamps();

            $table->foreign(['username'])->references(['username'])->on('motivationsw_accounts')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('motivationsw_bet_records');
    }
};

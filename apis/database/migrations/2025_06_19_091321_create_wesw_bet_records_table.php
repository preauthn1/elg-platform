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
        SchemaMonitor::create('wesw_bet_records', function (Blueprint $table) {
            $table->increments('id');
            $table->string('bet_id', 200)->unique()->comment('交易 id, gameRoundId from request');
            $table->string('ref_id', 200)->unique()->comment('與遊戲商核對時的交易編號');
            $table->string('game_round_id', 200)->comment('遊戲回合id');
            $table->string('username', 200)->index()->comment('玩家帳號');
            $table->string('game_id', 200)->comment('遊戲編號, gameId from request');
            $table->integer('status')->comment('1:debit,2:credit,3:rollback,4:resettlement');
            $table->string('type', 50)->comment('交易類型(細節查看遊戲商文件)');
            $table->string('game_code', 50)->default('TP01');
            $table->decimal('bet', 18, 4)->default(0)->comment('下注金額');
            $table->decimal('valid_bet', 18, 4)->default(0)->comment('有效下注金額');
            $table->decimal('payout', 18, 4)->default(0)->comment('贏得金額');
            $table->decimal('win_lose', 18, 4)->default(0)->comment('輸贏金額');
            $table->decimal('rolling_chips', 18, 4)->default(0)->comment('使用禮碼');
            $table->dateTime('bet_time')->comment('下注時間');
            $table->dateTime('payout_time')->nullable()->comment('派彩/取消/bonus時間');
            $table->json('infos')->comment('詳細資訊');
            $table->json('result_infos')->nullable();
            $table->timestamps();

            $table->foreign(['username'])->references(['username'])->on('wesw_accounts')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('wesw_bet_records');
    }
};

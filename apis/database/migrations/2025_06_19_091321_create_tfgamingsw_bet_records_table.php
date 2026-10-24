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
        SchemaMonitor::create('tfgamingsw_bet_records', function (Blueprint $table) {
            $table->increments('id');
            $table->string('bet_id', 200)->unique()->comment('ticketNum from request');
            $table->string('reference_bet_id', 200)->nullable()->comment('關聯交易 id, ticketId');
            $table->string('username', 30)->index()->comment('玩家帳號');
            $table->string('game_code', 30)->comment('遊戲編號, gameId from request');
            $table->string('type', 30)->comment('類別');
            $table->tinyInteger('status')->default(0)->comment('0: 未有結果, -1: 取消, 9: 結算');
            $table->decimal('bet', 18, 4)->default(0)->comment('下注金額');
            $table->decimal('valid_bet', 18, 4)->default(0)->comment('真實下注金額');
            $table->decimal('win_lose', 18, 4)->default(0)->comment('輸贏金額');
            $table->decimal('rolling_chips', 18, 4)->default(0)->comment('使用禮碼');
            $table->dateTime('bet_time')->comment('下注時間');
            $table->dateTime('payout_time')->comment('派彩/取消/bonus時間');
            $table->json('infos')->nullable()->comment('詳細資訊');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->foreign(['username'])->references(['username'])->on('tfgamingsw_accounts')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('tfgamingsw_bet_records');
    }
};

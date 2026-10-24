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
        SchemaMonitor::create('boracayfish_bet_records', function (Blueprint $table) {
            $table->increments('id');
            $table->string('bet_id', 200)->unique()->comment('交易 id, round_id from request');
            $table->string('username', 200)->index()->comment('玩家帳號');
            $table->string('game_code', 200)->comment('遊戲編號, game_id from request');
            $table->decimal('bet', 18, 4)->default(0)->comment('下注金額');
            $table->decimal('valid_bet', 18, 4)->default(0)->comment('真實下注金額');
            $table->decimal('win_lose', 18, 4)->default(0)->comment('輸贏金額');
            $table->dateTime('bet_time')->comment('下注時間');
            $table->dateTime('payout_time')->comment('派彩/取消/bonus時間');
            $table->json('infos')->nullable()->comment('詳細資訊');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');

            $table->foreign(['username'])->references(['username'])->on('boracayfish_accounts')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('boracayfish_bet_records');
    }
};

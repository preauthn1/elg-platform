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
        SchemaMonitor::create('afbsw_bet_records', function (Blueprint $table) {
            $table->id();
            $table->integer('member_id')->default(0)->comment('會員編號')->index();
            $table->string('username', 50)->comment('玩家帳號 playerId')->index();
            $table->string('bet_id', 50)->comment('注單號 betId')->unique();
            $table->string('game_code', 50)->comment('遊戲ID')->index();

            $table->decimal('bet', 18, 4)->default(0.0000)->comment('投注額');
            $table->decimal('valid_bet', 18, 4)->default(0.0000)->comment('有效投注額');
            $table->decimal('payout', 18, 4)->default(0.0000)->comment('派彩金額');
            $table->decimal('win_lose', 18, 4)->default(0.0000)->comment('輸贏金額');
            $table->decimal('rolling_chips', 18, 4)->default(0.0000)->comment('投注禮碼金額');

            $table->timestamp('bet_time')->comment('投注時間')->index();
            $table->timestamp('payout_time')->nullable()->default(null)->comment('結算時間')->index();
            $table->tinyInteger('status')->default(0)->comment('狀態 0:建立注單,1:已結算');

            $table->json('info')->nullable()->default(null)->comment('注單信息');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');

            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('afbsw_bet_records');
    }
};

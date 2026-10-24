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
        SchemaMonitor::create('clotsw_bet_records', function (Blueprint $table) {
            $table->increments('id');
            $table->string('bet_id', 200)->unique()->comment('ticketNum from request');
            $table->string('username', 30)->comment('玩家帳號');
            $table->string('game_code', 30)->comment('遊戲編號, gameId from request');
            $table->decimal('bet', 18, 4)->default(0)->comment('下注金額');
            $table->decimal('valid_bet', 18, 4)->default(0)->comment('有效下注金額');
            $table->decimal('win', 18, 4)->default(0)->comment('派彩金額');
            $table->decimal('win_lose', 18, 4)->default(0)->comment('輸贏金額');
            $table->decimal('rolling_chip', 18, 4)->default(0)->comment('使用禮碼');
            $table->dateTime('bet_time')->comment('下注時間');
            $table->dateTime('payout_time')->nullable()->comment('派彩/取消/bonus時間');
            $table->text('info')->nullable()->comment('詳細資訊');
            $table->tinyInteger('settle_status')->default(0)->comment('結算狀態 0=未結算 1=已結算 2=取消');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('clotsw_bet_records');
    }
};

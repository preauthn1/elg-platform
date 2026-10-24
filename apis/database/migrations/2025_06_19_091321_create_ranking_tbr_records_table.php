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
        SchemaMonitor::create('ranking_tbr_records', function (Blueprint $table) {
            $table->comment('存放total_bet_records輸贏有達標的投注記錄');
            $table->bigIncrements('id')->comment('total_bet_records流水號');
            $table->dateTime('summary_time')->index()->comment('時間');
            $table->integer('member_id')->index()->comment('會員系統編號');
            $table->integer('vendor_id')->index()->comment('遊戲商系統編號');
            $table->integer('game_id')->index()->comment('遊戲系統編號');
            $table->decimal('bet', 18, 4)->default(0)->comment('投注金額');
            $table->decimal('valid_bet', 18, 4)->default(0)->comment('有效投注金額');
            $table->decimal('win_lose', 18, 4)->default(0)->index()->comment('輸贏金額');
            $table->dateTime('bet_time')->index()->comment('投注時間(UTC)');
            $table->dateTime('payout_time')->index()->comment('派彩、結算時間(UTC)');
            $table->dateTime('created_at')->useCurrent()->comment('建立日期');
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('ranking_tbr_records');
    }
};

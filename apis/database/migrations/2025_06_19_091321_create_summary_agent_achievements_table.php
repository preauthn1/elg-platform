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
        SchemaMonitor::create('summary_agent_achievements', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->dateTime('summary_time')->index()->comment('彙總時間(UTC)');
            $table->integer('agent_id')->comment('代理ID');
            $table->integer('vendor_id')->comment('遊戲商ID');
            $table->integer('game_type_id')->comment('遊戲商ID');
            $table->decimal('total_bet', 18, 4)->default(0)->comment('總投注');
            $table->decimal('total_win_lose', 18, 4)->default(0)->comment('總輸贏');
            $table->decimal('total_jackpot', 18, 4)->default(0)->comment('總輸贏');
            $table->decimal('total_share_amount', 18, 4)->default(0)->comment('總輸贏');
            $table->decimal('total_commission', 18, 4)->default(0)->comment('總輸贏');
            $table->decimal('total_v_jackpot', 18, 4)->default(0)->comment('總VJackpot');
            $table->decimal('total_cost', 18, 4)->default(0)->comment('總VJackpot');
            $table->decimal('total_score', 18, 4)->default(0)->comment('總VJackpot');
            $table->decimal('proportion', 18, 4)->default(0)->comment('佔成');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('summary_agent_achievements');
    }
};

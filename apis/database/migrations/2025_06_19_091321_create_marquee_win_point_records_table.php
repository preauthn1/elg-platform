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
        SchemaMonitor::create('marquee_win_point_records', function (Blueprint $table) {
            $table->bigInteger('total_bet_record_id');
            $table->decimal('win', 18, 4)->comment('贏分');
            $table->integer('agent_id')->index()->comment('代理ID');
            $table->integer('vendor_id')->comment('遊戲商ID');
            $table->integer('member_id')->comment('玩家ID');
            $table->integer('game_id')->comment('遊戲ID');
            $table->dateTime('payout_time')->index()->comment('發放時間');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');

            $table->unique(['total_bet_record_id', 'agent_id'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('marquee_win_point_records');
    }
};

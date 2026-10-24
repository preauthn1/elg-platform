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
        SchemaMonitor::create('member_launch_logs', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->dateTime('launch_date')->index()->comment('Launch Date');
            $table->integer('member_id')->index()->comment('玩家系統編號(members.id)');
            $table->integer('wallet_type_id')->default(1)->index()->comment('錢包系統編號(wallet_types.id)');
            $table->integer('game_id')->default(0)->index()->comment('遊戲系統編號(games.id)');
            $table->integer('count')->comment('登入次數');
            $table->dateTime('created_at')->useCurrent()->comment('建立日期');
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('member_launch_logs');
    }
};

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
        SchemaMonitor::create('vip_reward_payout_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('vip_reward_id')->comment('vip_rewards.id');
            $table->timestamp('summary_start_time')->useCurrent()->comment('計算時間區間-開始');
            $table->timestamp('summary_end_time')->useCurrent()->comment('計算時間區間-結束');
            $table->integer('status')->default(0)->comment('狀態:0:未發放,1:已發放');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('vip_reward_payout_logs');
    }
};

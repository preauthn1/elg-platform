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
        SchemaMonitor::create('event_progressive_jackpot_settings', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('level')->unique()->comment('彩金等級');
            $table->decimal('deposit_point', 18, 4)->default(0)->comment('須累積儲值的金額(首儲是0)');
            $table->integer('reward_type')->comment('獎勵類型 1:固定金額, 2:百分比');
            $table->decimal('reward', 18, 4)->default(0)->comment('彩金金額(reward_type=1)');
            $table->decimal('reward_percentage', 8, 4)->default(0)->comment('金額倍數(reward_type=2)，200%存2.0，計算時直接乘不用再除100');
            $table->decimal('max_reward', 18, 4)->default(0)->comment('金額上限(reward_type=2)');
            $table->integer('expire_day')->default(0)->comment('多久後過期(天)');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('操作者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('event_progressive_jackpot_settings');
    }
};

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
        SchemaMonitor::create('vip_content_rewards', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('vip_content_id')->default(0)->index()->comment('VIP系統編號');
            $table->integer('vip_reward_id')->default(0)->index()->comment('VIP獎勵系統編號');
            $table->integer('type')->default(0)->comment('分數類別;0:固定金額(point)、1:%數(point_percentage)、2:額外返水(member_vip_prizes.point)');
            $table->decimal('max_point', 18, 4)->default(0)->comment('金額上限');
            $table->integer('point_type')->default(0)->comment('分數類別;1:平台點數、2:禮碼、3:實體獎勵');
            $table->decimal('condition', 18, 4)->default(0)->comment('條件');
            $table->decimal('point', 18, 4)->default(0)->comment('獎項金額');
            $table->decimal('point_percentage', 8, 4)->default(0)->comment('分數%數');
            $table->integer('expired_hour')->default(0)->comment('幾小時候過期;0:無期限、-1:發獎當天後過期');
            $table->integer('flow_limit_multiple')->default(0)->comment('稽核流水倍數');
            $table->tinyInteger('status')->default(0)->comment('狀態 0:關閉 1:開啟');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('vip_content_rewards');
    }
};

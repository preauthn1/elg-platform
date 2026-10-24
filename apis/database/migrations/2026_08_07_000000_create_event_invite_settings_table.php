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
        SchemaMonitor::create('event_invite_settings', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('agent_id')->unique()->comment('代理系統編號');
            $table->decimal('max_reward', 18, 4)->default(0)->comment('單筆最高獎勵');
            $table->decimal('realname_reward', 18, 4)->default(0)->comment('實名認證獎勵');
            $table->decimal('deposit_point', 18, 4)->default(0)->comment('儲值門檻點數');
            $table->decimal('deposit_reward', 18, 4)->default(0)->comment('儲值達標獎勵');
            $table->decimal('bet_point', 18, 4)->default(0)->comment('打碼門檻點數');
            $table->decimal('bet_reward', 18, 4)->default(0)->comment('打碼達標獎勵');
            $table->integer('block_type')->default(0)->comment('封鎖類型');
            $table->boolean('ip_block')->default(false)->comment('是否啟用IP封鎖');
            $table->boolean('mobile_block')->default(false)->comment('是否啟用手機號封鎖');
            $table->boolean('realname_block')->default(false)->comment('是否啟用實名封鎖');
            $table->integer('status')->default(0)->comment('狀態');
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
        SchemaMonitor::dropIfExists('event_invite_settings');
    }
};

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
        SchemaMonitor::create('bind_reward_details', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('bind_reward_id')->index()->comment('綁定獎勵 id');
            $table->unsignedInteger('grand_agent_id')->nullable()->comment('總代理 id');
            $table->string('phone', 50)->comment('手機號碼, 國碼+手機號碼');
            $table->tinyInteger('status')->default(0)->comment('狀態, 0: 未綁定, 1: 已綁定');
            $table->unsignedInteger('member_id')->nullable()->index()->comment('會員 id');
            $table->string('ip', 50)->nullable()->comment('IP');
            $table->timestamp('bind_time')->nullable()->comment('註冊時間');
            $table->timestamp('created_at')->useCurrent()->comment('建立時間');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新時間');

            $table->unique(['grand_agent_id', 'phone'], 'unique_index');

            $table->foreign('bind_reward_id')->references('id')->on('bind_rewards');
            $table->foreign('member_id')->references('id')->on('members');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('bind_reward_details');
    }
};

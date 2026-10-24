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
        SchemaMonitor::create('bind_rewards', function (Blueprint $table) {
            $table->increments('id');
            $table->string('reward_name', 200)->comment('獎勵名稱');
            $table->unsignedInteger('agent_id')->index()->comment('代理 id');
            $table->decimal('point', 18, 4)->default(0)->comment('獎勵金額');
            $table->integer('type')->comment('獎勵類型, 1: 平台點數, 2: 禮碼');
            $table->json('flow_limits')->nullable()->comment('稽核流水設定');
            $table->tinyInteger('status')->default(1)->comment('狀態, 1: 啟用, 2: 停用');
            $table->timestamp('start_time')->useCurrent()->comment('開始時間');
            $table->dateTime('end_time')->useCurrent()->comment('結束時間');
            $table->timestamp('created_at')->useCurrent()->comment('建立時間');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新時間');

            $table->foreign('agent_id')->references('id')->on('agents');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('bind_rewards');
    }
};

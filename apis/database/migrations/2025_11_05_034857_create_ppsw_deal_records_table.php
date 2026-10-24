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
        SchemaMonitor::create('ppsw_deal_records', function (Blueprint $table) {
            $table->id();
            $table->string('trace_id', 50)->comment('追蹤碼');
            $table->string('username', 50)->comment('玩家帳號 playerId');
            $table->string('bet_id', 50)->comment('注單號 roundId');
            $table->string('reference_no', 50)->comment('三方交易碼');
            $table->string('action', 20)->comment('請求動作');
            $table->text('request')->comment('原始請求');
            $table->tinyInteger('status')->default(0)->comment('狀態 0:失敗,1:成功');
            $table->timestamps();

            // 索引
            $table->unique(['username', 'reference_no', 'action'], 'ppsw_deal_records_idempotency_unique');
            $table->unique('trace_id');
            $table->index('username');
            $table->index('bet_id');
            $table->index('reference_no');
            $table->index('action');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('ppsw_deal_records');
    }
};

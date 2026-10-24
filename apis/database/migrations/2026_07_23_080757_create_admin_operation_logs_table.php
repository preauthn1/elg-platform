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
        SchemaMonitor::create('admin_operation_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->comment('執行者系統編號(User)');
            $table->string('operation', 64)->comment('操作代號(artisan 指令名)');
            $table->json('params')->nullable()->comment('執行參數');
            $table->tinyInteger('status')->default(0)->comment('狀態 0:待執行,1:執行中,2:成功,3:失敗');
            $table->mediumText('output')->nullable()->comment('執行輸出');
            $table->dateTime('started_at')->nullable()->comment('開始執行時間');
            $table->dateTime('finished_at')->nullable()->comment('執行結束時間');
            $table->timestamps();

            // 防重複派發查詢:operation + status IN (pending, running) + created_at >= 閾值
            $table->index(['operation', 'status', 'created_at']);
            // 操作紀錄頁過濾:執行者、時間區間
            $table->index('user_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('admin_operation_logs');
    }
};

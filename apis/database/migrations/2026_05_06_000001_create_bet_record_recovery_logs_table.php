<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('bet_record_recovery_logs', function (Blueprint $table) {
            $table->comment('注單人工補帳/撤回事件記錄（跨遊戲商通用，event-log 模式）');
            $table->id();
            $table->integer('vendor_id')->index()
                ->comment('遊戲商系統編號');
            $table->unsignedBigInteger('bet_record_id')
                ->comment('來源注單表 id（依 vendor_id 對應 XXX_bet_records）');
            $table->string('bet_id', 50)->index()
                ->comment('注單 BetId（冗余便於跨 vendor 追查）');
            $table->integer('member_id')->index()
                ->comment('會員系統編號');

            $table->string('type', 20)->index()
                ->comment('事件類型：recover=補帳、revert=撤回補帳');

            $table->timestamp('performed_at')->useCurrent()->index()
                ->comment('執行時間');
            $table->string('performed_by', 100)
                ->comment('執行者：command 名稱 / 客服姓名');
            $table->text('reason')
                ->comment('執行原因（必填）');
            $table->string('source', 200)->nullable()
                ->comment('資料來源：CSV 檔名:row、客服票號等');

            $table->json('before')
                ->comment('操作前快照');
            $table->json('after')
                ->comment('操作後快照');
            $table->decimal('balance_diff', 18, 4)->default(0)
                ->comment('玩家錢包淨變動：recover=正、revert=負');

            $table->unsignedBigInteger('parent_log_id')->nullable()->unique()
                ->comment('被撤回的 recover log id；type=revert 時填，type=recover 時為 NULL' .
                    '；MySQL UNIQUE 允許多 NULL，故同一注單可多次補帳，但每筆 recover 最多一次撤回');

            $table->timestamps();

            $table->index(['vendor_id', 'bet_record_id', 'performed_at'], 'idx_timeline');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('bet_record_recovery_logs');
    }
};

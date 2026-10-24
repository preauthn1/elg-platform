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
        SchemaMonitor::create('nex4dsw_deal_records', function (Blueprint $table) {
            $table->id();
            $table->string('bet_id', 50)->nullable()->comment('注單號 betId');
            $table->string('reference_no', 50)->nullable()->comment('交易號 transId');
            $table->string('action', 20)->comment('請求動作');
            $table->string('trace_id', 50)->comment('日誌追蹤碼');
            $table->tinyInteger('status')->default(0)->comment('狀態 0:失敗,1:成功');
            $table->string('message', 50)->nullable();
            $table->text('request')->comment('原始請求');
            $table->timestamps();

            // 索引
            $table->index('bet_id');
            $table->index('reference_no');
            $table->index('action');
            $table->index('trace_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('nex4dsw_deal_records');
    }
};

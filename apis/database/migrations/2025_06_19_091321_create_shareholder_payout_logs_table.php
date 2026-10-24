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
        SchemaMonitor::create('shareholder_payout_logs', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->dateTime('summary_time')->index()->comment('彙總時間(UTC)');
            $table->integer('agent_group_id')->default(0)->index()->comment('代理組別編號');
            $table->tinyInteger('status')->default(0)->index()->comment('0:未派發, 1:已派發, 2:待派發, 3:已編輯, 4:已結算, 5:已計算, 6:已發佈數
0:未派發 > 3:已輸入數據(未結算) > 5:已計算 > 6:已發佈數據 > 4:已結算 > 2:待派發 > 1:已派發');
            $table->text('memo')->nullable()->comment('備註');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('操作者');

            $table->unique(['summary_time', 'agent_group_id'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('shareholder_payout_logs');
    }
};

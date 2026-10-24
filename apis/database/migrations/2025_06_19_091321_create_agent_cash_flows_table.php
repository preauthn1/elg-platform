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
        SchemaMonitor::create('agent_cash_flows', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('cash_flow_info_id')->default(0)->index();
            $table->integer('cash_flow_id')->index()->comment('金流商系統編號');
            $table->integer('agent_group_id')->default(0)->index()->comment('代理群組編號');
            $table->decimal('rate', 15, 9)->default(0)->comment('轉換比例(2025/05/05提升至9位)');
            $table->text('info')->comment('API使用資訊');
            $table->integer('sorts')->default(0)->index()->comment('排序權重');
            $table->decimal('cost', 10, 4)->default(0);
            $table->tinyInteger('status')->default(0)->index()->comment('設置啟用狀態 0:停用, 1:啟用');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('agent_cash_flows');
    }
};

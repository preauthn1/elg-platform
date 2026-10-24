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
        SchemaMonitor::create('agent_cash_flow_order_types', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('cash_flow_info_id')->default(0);
            $table->integer('agent_cash_flow_id')->default(0)->index()->comment('金流商戶資料編號');
            $table->integer('order_type_id')->default(0)->index()->comment('支付方式編號');
            $table->integer('min_amount')->default(0)->comment('下限: 0為不限制');
            $table->integer('max_amount')->default(0)->comment('上限: 0為不限制');
            $table->string('available_amount', 191)->default('')->comment('固額: 150,300,500');
            $table->text('settings')->comment('Settings JSON');
            $table->integer('sorts')->default(0)->index()->comment('排序權重');
            $table->tinyInteger('status')->default(0)->index()->comment('設置啟用狀態 0:停用, 1:啟用');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('agent_cash_flow_order_types');
    }
};

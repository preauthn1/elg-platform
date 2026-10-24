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
        SchemaMonitor::create('simple_settlement_policies', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('agent_id')->comment('代理商系統編號');
            $table->string('period_type', 10)->comment('結算期間類型: daily, weekly, monthly');
            $table->tinyInteger('timezone')->comment('時區');
            $table->tinyInteger('is_auto_pay')->comment('是否自動派佣: 0 否, 1 是');
            $table->tinyInteger('is_active')->comment('是否啟用: 0 否, 1 是');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('simple_settlement_policies');
    }
};

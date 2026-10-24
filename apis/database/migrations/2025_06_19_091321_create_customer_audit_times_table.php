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
        SchemaMonitor::create('customer_audit_times', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('order_id')->default(0)->index()->comment('訂單編號');
            $table->integer('cash_flow_id')->default(0)->index()->comment('金流資料編號');
            $table->tinyInteger('type')->index()->comment('1:審核 2:通過');
            $table->dateTime('operator_time')->comment('操作時間');
            $table->integer('operator')->default(0)->comment('操作人');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('customer_audit_times');
    }
};

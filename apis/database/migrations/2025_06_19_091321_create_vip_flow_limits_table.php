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
        SchemaMonitor::create('vip_flow_limits', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('type')->default(0);
            $table->integer('member_id')->default(0)->index()->comment('會員系統編號');
            $table->decimal('deposit_amount', 18, 4)->default(0)->comment('充值金額');
            $table->decimal('flow_limit', 18, 4)->default(0)->comment('稽核流水金額');
            $table->decimal('reach', 18, 4)->default(0)->comment('達成金額');
            $table->string('remark', 100)->nullable()->comment('達成金額');
            $table->tinyInteger('status')->default(0)->comment('狀態 0:未達成 1:已達成');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('vip_flow_limits');
    }
};

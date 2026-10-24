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
        SchemaMonitor::create('sms_gateway_infos', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('sms_gateway_group_id')->default(0)->comment('簡訊群組ID(sms_gateway_groups.id)');
            $table->unsignedInteger('sms_gateway_id')->default(0)->comment('簡訊商ID');
            $table->string('info', 1000)->default('{}')->comment('簡訊商商戶資訊, 內容使用json儲存');
            $table->integer('sort')->default(0)->comment('簡訊商排序');
            $table->decimal('cost', 10, 4)->default(0)->comment('成本');
            $table->integer('status')->default(0)->comment('狀態; 0:停用, 1:啟用');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('sms_gateway_infos');
    }
};

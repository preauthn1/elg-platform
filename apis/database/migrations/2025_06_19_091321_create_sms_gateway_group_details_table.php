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
        SchemaMonitor::create('sms_gateway_group_details', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('agent_group_id')->default(0)->comment('agent_group.id');
            $table->string('setting', 200)->default('{}')->comment('簡訊商資訊, json格式; {"token_expired":"token有效時間", "max_send":"有效時間最大發送次數"}');
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
        SchemaMonitor::dropIfExists('sms_gateway_group_details');
    }
};

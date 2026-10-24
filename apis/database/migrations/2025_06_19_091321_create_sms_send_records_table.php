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
        SchemaMonitor::create('sms_send_records', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('sms_gateway_id')->default(0)->index()->comment('簡訊商ID(sms_gateways.id)');
            $table->integer('agent_id')->default(0)->index()->comment('agents.id');
            $table->unsignedInteger('member_id')->default(0)->index()->comment('玩家ID');
            $table->string('mobile', 20)->comment('發送號碼');
            $table->string('message_content', 100)->comment('簡訊發送內容');
            $table->string('transaction_id', 50)->nullable()->comment('簡訊商發送ID');
            $table->text('response_message')->nullable()->comment('簡訊商response data(json)');
            $table->integer('status')->default(0)->index()->comment('發送狀態;0:未發送,1:發送成功,2:發送失敗');
            $table->timestamp('created_at')->useCurrent()->index()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('sms_send_records');
    }
};

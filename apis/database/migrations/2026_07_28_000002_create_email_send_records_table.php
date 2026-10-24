<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('email_send_records', function (Blueprint $table) {
            $table->comment('電子郵件發送紀錄');
            $table->increments('id')->comment('流水號');
            $table->integer('email_gateway_id')->default(0)->index()->comment('電子郵件提供商ID(email_gateways.id); 0:未設定提供商');
            $table->integer('agent_id')->default(0)->index()->comment('營運商系統編號(agents.id)');
            $table->unsignedInteger('member_id')->default(0)->index()->comment('玩家ID');
            $table->string('sender', 191)->default('')->comment('寄件信箱');
            $table->string('receiver', 191)->comment('收件信箱');
            $table->text('subject')->nullable()->comment('郵件主旨');
            $table->text('message_content')->nullable()->comment('郵件內容');
            $table->text('response_message')->nullable()->comment('回應內容');
            $table->integer('status')->default(0)->index()->comment('發送狀態; 0:未發送, 1:發送成功, 2:發送失敗');
            $table->timestamp('created_at')->useCurrent()->index()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('email_send_records');
    }
};

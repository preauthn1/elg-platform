<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('email_gateways', function (Blueprint $table) {
            $table->comment('電子郵件提供商(SMTP)設定, 以電子郵件設置組別為單位');
            $table->increments('id')->comment('流水號');
            $table->integer('agent_group_id')->comment('電子郵件群組ID(agent_groups.id, agent_group_type=7)');
            $table->string('email', 191)->comment('寄件信箱, 同時作為 SMTP 帳號');
            $table->text('password')->comment('SMTP 密碼(加密後)');
            $table->string('host', 191)->comment('SMTP 主機');
            $table->unsignedSmallInteger('port')->default(587)->comment('SMTP 連接埠');
            $table->string('scheme', 10)->default('smtp')->comment('連線協定; smtp:STARTTLS, smtps:SSL');
            $table->string('from_name', 191)->default('')->comment('寄件人顯示名稱');
            $table->integer('sort')->default(0)->comment('排序');
            $table->integer('status')->default(0)->index()->comment('狀態; 0:停用, 1:啟用');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('更新者ID(agents.id)');

            $table->unique(['agent_group_id', 'email']);
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('email_gateways');
    }
};

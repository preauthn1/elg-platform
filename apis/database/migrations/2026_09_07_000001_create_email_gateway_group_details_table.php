<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * 電子郵件設置組別（agent_groups.agent_group_type=7）的群組設定 工單4286
 *
 * 形狀比照 sms_gateway_group_details：一個組別一筆，setting 存 JSON。
 * 驗證碼寄信的有效時間與發送上限改讀本表，不再共用簡訊組別的設定。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('email_gateway_group_details', function (Blueprint $table) {
            $table->comment('電子郵件設置組別的群組設定');
            $table->increments('id')->comment('流水號');
            $table->integer('agent_group_id')->default(0)->comment('agent_groups.id(agent_group_type=7)');
            $table->string('setting', 200)->default('{}')->comment('群組設定, json格式; {"token_expired":"token有效時間(分)", "max_send":"有效時間內最大發送次數"}');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('更新者ID(agents.id)');
            // 一個組別只會有一筆，updateOrCreate 以此命中
            $table->unique('agent_group_id', 'uk_email_gateway_group_details_group');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('email_gateway_group_details');
    }
};

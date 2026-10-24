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
        SchemaMonitor::create('member_settings', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->integer('member_id')->comment('帳號系統編號(User)');
            $table->integer('activity_limit_time')->comment('最長非活動狀態時間(minute)');
            $table->tinyInteger('push_notify')->comment('接受優惠通知-推送通知');
            $table->tinyInteger('sms_notify')->comment('接受優惠通知-短信提示');
            $table->tinyInteger('email_notify')->comment('接受優惠通知-電子郵件');
            $table->tinyInteger('message_box_notify')->comment('站內優惠通知-信息收件箱');
            $table->tinyInteger('message_popup_notify')->comment('站內優惠通知-優惠彈出信息');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('member_settings');
    }
};

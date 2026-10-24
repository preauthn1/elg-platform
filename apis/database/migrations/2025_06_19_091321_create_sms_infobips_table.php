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
        SchemaMonitor::create('sms_infobips', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('member_id')->comment('玩家帳號');
            $table->string('mobile', 191)->comment('電話號碼');
            $table->string('text', 191)->comment('訊息內容');
            $table->integer('smsCount')->nullable()->comment('訊息分成幾則');
            $table->string('message_id', 191)->nullable()->comment('訊息編號');
            $table->dateTime('send_at')->nullable()->comment('發送時間');
            $table->dateTime('done_at')->nullable()->comment('完成時間');
            $table->decimal('price', 10)->nullable()->comment('費用');
            $table->string('currency', 10)->nullable()->comment('幣種');
            $table->integer('status_group_id')->nullable()->comment('群組識別碼');
            $table->string('status_group_name', 191)->nullable()->comment('群組名稱');
            $table->integer('status_id')->nullable()->comment('識別碼');
            $table->string('status_name', 191)->nullable()->comment('名稱');
            $table->string('status_description', 191)->nullable()->comment('說明');
            $table->boolean('status')->default(false)->comment('狀態');
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
        SchemaMonitor::dropIfExists('sms_infobips');
    }
};

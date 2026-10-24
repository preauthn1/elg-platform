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
        SchemaMonitor::create('firebase_cloud_messages', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('member_id')->comment('會員系統編號');
            $table->string('title', 191)->comment('標題');
            $table->string('content', 191)->comment('內容');
            $table->string('url', 191)->comment('click action網址');
            $table->string('multicast_id', 191)->nullable()->comment('multicast_id');
            $table->string('message_id', 191)->nullable()->comment('message_id');
            $table->string('error', 191)->nullable()->comment('錯誤訊息');
            $table->boolean('status')->default(false)->comment('狀態0:未發送,1:發送成功,2:發送失敗');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('firebase_cloud_messages');
    }
};

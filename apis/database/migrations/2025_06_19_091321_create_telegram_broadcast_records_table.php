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
        SchemaMonitor::create('telegram_broadcast_records', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('telegram_broadcast_message_id')->comment('telegram_broadcast_messages.id');
            $table->integer('member_id')->comment('members.id');
            $table->text('request_content')->comment('請求數據');
            $table->text('response_content')->comment('請求回應數據');
            $table->integer('status')->default(0)->comment('狀態: 0 等待發送, 1 發送中, 2 發送成功, 3 發送失敗');
            $table->dateTime('created_at')->useCurrent()->comment('建立日期');
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('telegram_broadcast_records');
    }
};

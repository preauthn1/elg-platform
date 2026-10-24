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
        SchemaMonitor::create('announcement_messages_reads', function (Blueprint $table) {
            $table->comment('紀錄某一則訊息是否已讀');
            $table->increments('id');
            $table->integer('announcement_message_id')->comment('FK對應 announcement_message');
            $table->integer('member_id')->comment('會員id');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('announcement_messages_reads');
    }
};

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
        SchemaMonitor::create('system_notification_reads', function (Blueprint $table) {
            $table->comment('記錄會員最後的已讀時間 (系統通知)');
            $table->increments('id');
            $table->integer('member_id')->unique();
            $table->timestamp('last_read_time')->useCurrent()->index()->comment('會員最後已讀時間');
            $table->timestamp('created_at')->useCurrent()->index()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('system_notification_reads');
    }
};

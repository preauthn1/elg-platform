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
        SchemaMonitor::create('vendor_request_fails', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('vendor_id')->index()->comment('遊戲商系統編號');
            $table->integer('general_agent_id')->default(1)->comment('總代理ID編號');
            $table->integer('status')->default(0)->index()->comment('狀態，0:停止轉帳, 1: 設定維護, 2:恢復轉帳, ');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('vendor_request_fails');
    }
};

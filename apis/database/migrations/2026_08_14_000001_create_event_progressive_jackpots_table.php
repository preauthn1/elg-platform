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
        SchemaMonitor::create('event_progressive_jackpots', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('agent_id')->unique()->comment('代理系統編號');
            $table->text('title')->nullable()->comment('活動標題');
            $table->text('content')->nullable()->comment('活動內文');
            $table->integer('first_deposit_type')->default(0)->comment('首儲類別;1: 創建帳號後第一筆、2: 活動開始後第一筆');
            $table->boolean('ip_block')->default(false)->comment('是否啟用IP封鎖');
            $table->boolean('mobile_block')->default(false)->comment('是否啟用手機號封鎖');
            $table->boolean('realname_block')->default(false)->comment('是否啟用實名封鎖');
            $table->integer('sort')->default(0)->comment('前端排序');
            $table->integer('status')->default(0)->comment('活動開關');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('操作者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('event_progressive_jackpots');
    }
};

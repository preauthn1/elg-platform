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
        SchemaMonitor::create('new4d2026_accounts', function (Blueprint $table) {
            // 欄位
            $table->id();
            $table->integer('vendor_info_id')->default(0)->comment('遊戲商設定編號');
            $table->integer('member_id')->default(0)->comment('會員編號');
            $table->string('username', 191)->comment('玩家帳號');
            $table->string('password', 191)->comment('玩家密碼');
            $table->tinyInteger('status')->default(1)->comment('狀態 0:關閉,1:正常');
            $table->integer('admin')->default(0);
            $table->timestamps();

            // 索引
            $table->index('member_id');
            $table->index('username');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('new4d2026_accounts');
    }
};

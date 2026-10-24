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
        SchemaMonitor::create('top55sw_accounts', function (Blueprint $table) {
            $table->id();
            $table->integer('vendor_info_id')->default(0)->index()->comment('遊戲商設定編號');
            $table->string('username', 200)->unique()->comment('玩家帳號');
            $table->integer('member_id')->default(0)->index()->comment('會員系統編號');
            $table->boolean('status')->default(1)->comment('狀態 0:關閉,1:正常');
            $table->integer('admin')->default(0)->comment('管理員編號');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('top55sw_accounts');
    }
};

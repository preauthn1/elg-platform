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
        SchemaMonitor::create('ppsw_accounts', function (Blueprint $table) {
            // 欄位
            $table->id();
            $table->integer('vendor_info_id')->default(0)->comment('遊戲商設定編號');
            $table->integer('member_id')->default(0)->comment('會員編號');
            $table->string('username', 50)->comment('玩家帳號');
            $table->integer('player_id')->nullable()->comment('遊戲方玩家編號');
            $table->string('token', 50)->nullable();
            $table->string('currency', 5)->comment('幣別');
            $table->string('country', 5)->comment('幣別');
            $table->tinyInteger('status')->default(1)->comment('狀態 0:關閉,1:正常');
            $table->integer('admin')->default(0);
            $table->timestamps();

            // 索引
            $table->index('vendor_info_id');
            $table->index('member_id');
            $table->index('username');
            $table->index('player_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('ppsw_accounts');
    }
};

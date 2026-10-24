<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('kissh5sw_accounts', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('vendor_info_id')->default(0)->index()->comment('遊戲商設定編號');
            $table->string('username', 50)->index()->comment('玩家帳號');
            $table->integer('member_id')->default(0)->index()->comment('會員系統編號');
            $table->string('auth_token', 200)->nullable()->comment('登入遊戲更新');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->tinyInteger('status')->default(1)->comment('狀態 0:關閉,1:正常');
            $table->integer('admin')->default(0);

            $table->index(['vendor_info_id', 'member_id', 'status'], 'idx_kissh5_bind_lookup');
            $table->index(['vendor_info_id', 'status', 'member_id', 'id'], 'idx_kissh5_pool_pick');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('kissh5sw_accounts');
    }
};

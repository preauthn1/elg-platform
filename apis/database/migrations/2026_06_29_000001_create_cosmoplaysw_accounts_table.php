<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('cosmoplaysw_accounts', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('vendor_info_id')->default(0)->index()->comment('遊戲商設定編號');
            $table->string('username', 100)->index()->comment('玩家帳號（對應 CosmoPlay PlayerID = partner_code-username）');
            $table->integer('member_id')->default(0)->index()->comment('會員系統編號');
            $table->string('session_token', 200)->default('')->index()->comment('登入遊戲更新的 AuthToken');
            $table->tinyInteger('status')->default(1)->comment('狀態 0:關閉,1:正常');
            $table->integer('admin')->default(0);
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('cosmoplaysw_accounts');
    }
};

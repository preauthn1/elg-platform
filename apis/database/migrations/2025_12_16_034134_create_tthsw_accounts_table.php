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
        SchemaMonitor::create('tthsw_accounts', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('vendor_info_id')->default(0)->index()->comment('遊戲商設定編號');
            $table->integer('member_id')->default(0)->index()->comment('會員系統編號');
            $table->string('username')->index()->comment('玩家帳號');
            $table->string('auth_token')->unique()->nullable()->comment('Auth Token');
            $table->string('session_token')->unique()->nullable()->comment('Session Token');
            $table->boolean('status')->default(1)->comment('狀態 0:關閉,1:正常');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->tinyInteger('admin')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('tthsw_accounts');
    }
};

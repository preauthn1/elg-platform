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
        SchemaMonitor::create('obet33_accounts', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('vendor_info_id')->default(0)->index()->comment('遊戲商設定編號');
            $table->string('username', 191)->index()->comment('玩家帳號');
            $table->string('username_modified', 191)->index()->comment('玩家帳號(含前綴)');
            $table->decimal('balance', 10)->default(0)->comment('obet33餘額');
            $table->integer('member_id')->default(0)->index()->comment('會員系統編號');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('obet33_accounts');
    }
};

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
        SchemaMonitor::create('wmlivesw_accounts', function (Blueprint $table) {
            $table->comment('完美真人遊戲帳號');
            $table->increments('id');
            $table->integer('vendor_info_id')->default(0)->index()->comment('遊戲商設定編號');
            $table->integer('member_id')->default(0)->index()->comment('會員系統編號');
            $table->string('username', 30)->unique()->comment('玩家帳號');
            $table->string('password', 64)->comment('密碼');
            $table->string('nickname', 30)->comment('遊戲商的玩家帳號名');
            $table->integer('admin')->default(0)->comment('管理員編號');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('wmlivesw_accounts');
    }
};

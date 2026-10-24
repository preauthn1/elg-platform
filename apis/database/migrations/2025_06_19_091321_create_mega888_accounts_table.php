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
        SchemaMonitor::create('mega888_accounts', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('vendor_info_id')->default(0)->index()->comment('遊戲商資訊編號');
            $table->string('username', 191)->unique()->comment('mega888帳號');
            $table->string('password', 191)->nullable()->comment('密碼');
            $table->string('player_id', 191)->nullable()->index()->comment('玩家帳號');
            $table->string('user_id', 191)->nullable()->comment('Mega888系統內部分配id(登入帳號)');
            $table->decimal('balance', 10)->default(0)->comment('mega888餘額');
            $table->integer('member_id')->default(0)->index()->comment('會員系統編號');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('mega888_accounts');
    }
};

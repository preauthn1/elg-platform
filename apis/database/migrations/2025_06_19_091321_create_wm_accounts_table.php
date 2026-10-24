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
        SchemaMonitor::create('wm_accounts', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('vendor_info_id')->default(0)->comment('遊戲商設定編號');
            $table->string('username', 20)->comment('玩家帳號帳號');
            $table->string('user_id', 32)->nullable()->index()->comment('避免同一個USERNAME重覆建立的編號(如果需要创建两次, 回应将会是相同, 但 WAPI 仅在第一次使用时生成新玩家。)');
            $table->string('player_id', 191)->comment('使用者授權碼');
            $table->decimal('balance', 18, 4)->default(0)->comment('玩家餘額');
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
        SchemaMonitor::dropIfExists('wm_accounts');
    }
};

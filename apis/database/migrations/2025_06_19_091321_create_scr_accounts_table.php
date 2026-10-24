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
        SchemaMonitor::create('scr_accounts', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('vendor_info_id')->default(0)->comment('遊戲商資訊編號');
            $table->string('username', 191)->unique()->comment('scr帳號');
            $table->string('player_id', 191)->nullable()->index()->comment('918玩家帳號');
            $table->string('password', 20)->nullable()->comment('密碼');
            $table->decimal('balance', 18, 4)->default(0)->comment('scr餘額');
            $table->integer('vendor_id')->default(0)->comment('營運商編號');
            $table->integer('member_id')->default(0)->index()->comment('會員帳號');
            $table->integer('status')->default(0)->comment('狀態0:未取餘額，1:取得舊帳號餘額，2:轉移完成，3:剩餘點數回收完成');
            $table->string('remark', 191)->nullable()->comment('備註');
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
        SchemaMonitor::dropIfExists('scr_accounts');
    }
};

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
        SchemaMonitor::create('user_login_logs', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('user_id')->comment('管理者帳號系統編號(User)');
            $table->string('ip', 45)->comment('IP地址');
            $table->tinyInteger('status')->comment('狀態：0失敗，1成功');
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
        SchemaMonitor::dropIfExists('user_login_logs');
    }
};

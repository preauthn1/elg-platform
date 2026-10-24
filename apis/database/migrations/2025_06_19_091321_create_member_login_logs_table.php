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
        SchemaMonitor::create('member_login_logs', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('member_id')->index()->comment('會員帳號系統編號(Member)');
            $table->string('ip', 45)->comment('IP地址');
            $table->tinyInteger('status')->index()->comment('狀態：0失敗，1成功');
            $table->timestamp('created_at')->useCurrent()->index()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('member_login_logs');
    }
};

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
        SchemaMonitor::create('users', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->integer('parent')->comment('上層管理員系統編號(User)');
            $table->string('username', 32)->unique()->comment('姓名');
            $table->string('nickname', 191)->nullable()->comment('暱稱');
            $table->string('email', 50)->comment('電子信箱');
            $table->string('password', 128)->comment('密碼');
            $table->string('mobile', 30)->nullable()->comment('聯繫號碼');
            $table->integer('timezone_id')->nullable()->comment('時區系統編號');
            $table->tinyInteger('status')->default(0)->comment('帳號狀態 0:未認證,1:正常,2:鎖定');
            $table->dateTime('last_login')->nullable()->comment('最後登入時間');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();
            $table->integer('admin')->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('users');
    }
};

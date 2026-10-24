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
        SchemaMonitor::create('user_menus', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->string('code', 191)->unique()->comment('檔案名稱');
            $table->string('name', 191)->comment('選單功能名稱');
            $table->integer('parent')->comment('選單系統編號，0為root menu');
            $table->string('url', 50)->comment('選單網址');
            $table->string('symbol', 50)->comment('選單符號');
            $table->string('action', 50)->comment('操作動作');
            $table->integer('status')->comment('狀態 0:隱藏,1:正常');
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
        SchemaMonitor::dropIfExists('user_menus');
    }
};

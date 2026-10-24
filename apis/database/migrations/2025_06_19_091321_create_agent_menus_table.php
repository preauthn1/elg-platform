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
        SchemaMonitor::create('agent_menus', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->string('code', 191)->index()->comment('檔案名稱');
            $table->string('name', 191)->comment('選單功能名稱');
            $table->integer('parent')->index()->comment('代理後台選單系統編號，0為root menu');
            $table->string('url', 191)->comment('選單網址');
            $table->string('symbol', 191)->nullable()->comment('選單符號');
            $table->string('action', 191)->comment('功能');
            $table->integer('sort')->default(0)->comment('排序');
            $table->boolean('status')->nullable()->default(false)->index()->comment('狀態');
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
        SchemaMonitor::dropIfExists('agent_menus');
    }
};

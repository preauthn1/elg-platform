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
        SchemaMonitor::create('agent_permissions', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->integer('agent_id')->index()->comment('代理系統編號(Agent)');
            $table->integer('agent_menu_id')->index()->comment('代理後台選單系統編號(AgentMenu)');
            $table->string('action', 191)->comment('可操作功能：view,create,update,delete(逗號分隔)');
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
        SchemaMonitor::dropIfExists('agent_permissions');
    }
};

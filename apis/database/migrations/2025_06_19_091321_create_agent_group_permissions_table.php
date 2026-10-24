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
        SchemaMonitor::create('agent_group_permissions', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('agent_id')->default(0)->index()->comment('代理系統編號');
            $table->integer('agent_group_id')->index()->comment('代理組別ID');
            $table->integer('status')->default(1)->index()->comment('狀態; 1:啟用, 0:停用');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('agent_group_permissions');
    }
};

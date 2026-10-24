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
        SchemaMonitor::create('agent_vendors', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('agent_id')->index()->comment('營運商系統編號');
            $table->integer('vendor_id')->index()->comment('遊戲商系統編號');
            $table->integer('vendor_info_id')->default(0)->comment('遊戲商資訊編號');
            $table->boolean('status')->default(false)->index()->comment('狀態');
            $table->boolean('self_status')->default(true)->comment('只影響自己層級的status開關');
            $table->integer('backend_show')->default(0)->comment('後台顯示');
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
        SchemaMonitor::dropIfExists('agent_vendors');
    }
};

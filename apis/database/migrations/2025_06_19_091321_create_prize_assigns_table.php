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
        SchemaMonitor::create('prize_assigns', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('event_id')->comment('活動系統編號');
            $table->integer('agent_id')->comment('營運商系統編號');
            $table->decimal('percentage', 10)->comment('開獎比例');
            $table->tinyInteger('status')->default(0)->comment('狀態0:停用1:啟用');
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
        SchemaMonitor::dropIfExists('prize_assigns');
    }
};

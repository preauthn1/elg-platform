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
        SchemaMonitor::create('agent_vendor_disables', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('agent_id')->comment('代理商編號');
            $table->integer('vendor_id')->comment('遊戲商編號');
            $table->string('flag', 20)->nullable();
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('agent_vendor_disables');
    }
};

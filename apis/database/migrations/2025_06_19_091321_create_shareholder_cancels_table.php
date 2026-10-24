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
        SchemaMonitor::create('shareholder_cancels', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->date('summary_date')->index()->comment('日期');
            $table->integer('agent_id')->default(0)->index()->comment('代理系統編號');
            $table->tinyInteger('status')->comment('狀態');
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
        SchemaMonitor::dropIfExists('shareholder_cancels');
    }
};

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
        SchemaMonitor::create('agent_operate_logs', function (Blueprint $table) {
            $table->comment('代理後台各種操作紀錄(e.g. 改密碼、修改帳號...)');
            $table->increments('id');
            $table->integer('agent_id');
            $table->string('type', 50);
            $table->string('reference_id', 50);
            $table->text('debug_info');
            $table->bigInteger('used_ip')->nullable()->default(0);
            $table->string('ip', 100);
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('agent_operate_logs');
    }
};

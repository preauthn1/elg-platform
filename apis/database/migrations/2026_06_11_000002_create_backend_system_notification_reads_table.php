<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('backend_system_notification_reads', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->integer('agent_id');
            $table->timestamp('last_read_time')->useCurrent()->comment('代理最後已讀時間');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate()->comment('更新日期');

            $table->unique('agent_id');
            $table->index('created_at');
            $table->index('last_read_time');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('backend_system_notification_reads');
    }
};

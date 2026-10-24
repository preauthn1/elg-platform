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
        SchemaMonitor::create('flow_limit_control_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('stat_start_id')->comment('統計開始ID');
            $table->integer('stat_end_id')->comment('統計結束ID');
            $table->dateTime('exec_start_time')->comment('執行開始時間');
            $table->dateTime('exec_end_time')->comment('執行結束時間');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('flow_limit_control_logs');
    }
};

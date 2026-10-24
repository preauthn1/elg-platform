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
        SchemaMonitor::create('summary_control_logs', function (Blueprint $table) {
            $table->comment('彙整排程時間控制表');
            $table->increments('id')->comment('流水號');
            $table->integer('vendor_id')->nullable()->index()->comment('1~10000:遊戲商編號,
10001~11000:SP,
11001~12000:RD-Studio job');
            $table->dateTime('stat_start_time')->nullable()->comment('統計開始時間');
            $table->dateTime('stat_end_time')->nullable()->comment('統計結束時間');
            $table->dateTime('exec_start_time')->nullable()->comment('執行開始時間');
            $table->dateTime('exec_end_time')->nullable()->comment('執行結束時間');
            $table->string('params', 512)->nullable();
            $table->dateTime('created_at')->useCurrent()->comment('建立日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('summary_control_logs');
    }
};

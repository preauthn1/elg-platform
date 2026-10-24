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
        SchemaMonitor::create('summary_control_tables', function (Blueprint $table) {
            $table->comment('彙整排程時間控制表');
            $table->increments('id')->comment('流水號');
            $table->integer('vendor_id')->nullable()->unique()->comment('遊戲商編號; 10000以上為功能編號');
            $table->string('vendor_code', 50)->nullable()->comment('遊戲商代碼');
            $table->dateTime('stat_start_time')->nullable()->comment('統計開始時間');
            $table->dateTime('stat_end_time')->nullable()->comment('統計結束時間');
            $table->bigInteger('stat_start_id')->nullable();
            $table->bigInteger('stat_end_id')->nullable();
            $table->dateTime('exec_start_time')->nullable()->comment('執行開始時間');
            $table->dateTime('exec_end_time')->nullable()->comment('執行結束時間');
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('summary_control_tables');
    }
};

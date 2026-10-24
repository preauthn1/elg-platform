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
        SchemaMonitor::create('vendor_info_last_end_times', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('vendor_info_id')->comment('廠商系統商戶編號');
            $table->dateTime('last_end_time')->comment('最後取得時間');
            $table->text('last_log')->comment('最後取得log');
            $table->text('last_error')->comment('最後取得錯誤');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('vendor_info_last_end_times');
    }
};

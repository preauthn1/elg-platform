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
        SchemaMonitor::create('timezones', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->string('time_offset', 15)->comment('偏移時間ex:UTC+12:00');
            $table->tinyInteger('is_summer')->default(0)->comment('是否為夏令時');
            $table->date('summer_start')->comment('夏令時開始日期');
            $table->date('summer_end')->comment('夏令時結束日期');
            $table->tinyInteger('status')->default(0)->comment('狀態 0:停用,1:啟用');
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
        SchemaMonitor::dropIfExists('timezones');
    }
};

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
        SchemaMonitor::create('vendor_maintenances', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('vendor_id')->index()->comment('遊戲商名稱');
            $table->tinyInteger('type')->default(1)->comment('關閉類型，1:遊戲跟錢包都關閉、2:關閉遊戲、3：關閉錢包');
            $table->dateTime('start_time')->index()->comment('開始時間');
            $table->dateTime('end_time')->index()->comment('結束時間');
            $table->integer('agent_id')->default(1)->index()->comment('代理ID');
            $table->integer('exclude_agent_id')->default(0)->comment('排除代理ID');
            $table->tinyInteger('status')->default(0)->index()->comment('狀態0:停用1:啟用');
            $table->text('memo')->nullable();
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
        SchemaMonitor::dropIfExists('vendor_maintenances');
    }
};

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
        SchemaMonitor::create('cash_flow_infos', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('cash_flow_id')->default(0)->comment('cash_flows.Id');
            $table->text('info')->nullable()->comment('API使用資訊');
            $table->tinyInteger('status')->default(0)->comment('設置啟用狀態 0:停用, 1:啟用');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');
            $table->integer('admin')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('cash_flow_infos');
    }
};

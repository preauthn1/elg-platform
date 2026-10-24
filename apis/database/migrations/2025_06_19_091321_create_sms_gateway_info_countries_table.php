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
        SchemaMonitor::create('sms_gateway_info_countries', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('sms_gateway_info_id')->comment('簡訊商戶資訊ID; sms_gateway_infos.id');
            $table->integer('country_id')->comment('國家ID; countries.id');
            $table->tinyInteger('status')->default(0)->comment('狀態; 1:啟用, 0:停用');
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
        SchemaMonitor::dropIfExists('sms_gateway_info_countries');
    }
};

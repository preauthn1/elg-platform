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
        SchemaMonitor::create('sms_gateways', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->string('name', 45)->comment('簡訊商名稱');
            $table->string('code', 10)->comment('簡訊商代碼');
            $table->string('info', 200)->default('{}')->comment('簡訊商輸入資訊');
            $table->string('url', 200)->comment('簡訊商 API URL');
            $table->string('memo', 200)->comment('備註');
            $table->integer('status')->default(0)->comment('狀態; 0:停用, 1:啟用');
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
        SchemaMonitor::dropIfExists('sms_gateways');
    }
};

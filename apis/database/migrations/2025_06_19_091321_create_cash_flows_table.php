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
        SchemaMonitor::create('cash_flows', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->string('third_party_code', 20)->nullable()->comment('三方代碼; 如果是聚合金流商, 就用同一個代碼');
            $table->string('name', 191)->comment('金流商名稱');
            $table->decimal('rate', 11, 5)->default(1);
            $table->decimal('percentage', 11, 5)->default(0);
            $table->integer('type')->index()->comment('支付類別; 1.線上支付, 2線下支付, 3.代付, 4.自助洗分');
            $table->boolean('status')->comment('狀態0:停用,1:啟用');
            $table->string('remark', 45)->nullable();
            $table->string('info', 191)->comment('API使用資訊');
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
        SchemaMonitor::dropIfExists('cash_flows');
    }
};

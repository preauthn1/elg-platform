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
        SchemaMonitor::create('yxfs', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('order_id')->comment('訂單系統編號');
            $table->decimal('api_amount', 10)->comment('API發起金額');
            $table->decimal('real_amount', 10)->nullable()->comment('實際支付金額');
            $table->string('url', 32)->nullable()->comment('呼叫地址');
            $table->string('bank_card_id', 32)->nullable()->comment('銀行卡號');
            $table->string('bank_user_name', 32)->nullable()->comment('銀行卡姓名');
            $table->string('state', 16)->nullable()->comment('狀態文字');
            $table->text('remark')->nullable()->comment('備註');
            $table->tinyInteger('status')->default(0)->comment('狀態1:待處理,2:處理中,3:成功,4:操作失敗,5:操作取消');
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
        SchemaMonitor::dropIfExists('yxfs');
    }
};

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
        SchemaMonitor::create('ncpays', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('order_id')->nullable()->comment('訂單系統編號');
            $table->integer('ncpay_deposit_id')->nullable()->comment('存款編號');
            $table->integer('ncpay_order_id')->nullable()->index()->comment('訂單編號');
            $table->string('third_party_id', 32)->nullable()->comment('第三方訂單編號');
            $table->string('bank', 16)->nullable()->comment('銀行編號');
            $table->decimal('amount', 10)->nullable()->comment('金額');
            $table->decimal('fee', 10)->nullable()->comment('手續費');
            $table->string('index', 191)->nullable();
            $table->text('remark')->nullable()->comment('備註');
            $table->string('ncpay_status', 16)->comment('狀態文字');
            $table->integer('ncpay_updated')->default(0)->comment('NCPAY更新時間');
            $table->integer('ncpay_created')->default(0)->comment('NCPAY建立時間');
            $table->string('error', 191)->nullable()->comment('錯誤訊息');
            $table->string('code', 191)->nullable()->comment('錯誤詳情');
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
        SchemaMonitor::dropIfExists('ncpays');
    }
};

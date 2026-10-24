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
        SchemaMonitor::create('order_types', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->integer('cash_flow_id')->default(0)->index()->comment('金流商編號');
            $table->integer('parent')->default(0)->index()->comment('上層支付方式系統編號(order_type)');
            $table->string('name', 50)->comment('類別名稱');
            $table->string('key', 50)->comment('支付方式代碼');
            $table->integer('type')->default(0)->comment('付款方式;1:電子、2:網銀轉帳、3:QR掃碼、4:加密貨幣');
            $table->integer('currency_id')->default(0)->comment('幣別系統編號(currencies.id)');
            $table->text('info')->nullable();
            $table->tinyInteger('choose_bank')->default(0)->comment('是否由我們這邊選擇銀行');
            $table->tinyInteger('need_first_name')->default(0)->comment('是否需要實名');
            $table->integer('sorts')->default(0)->index()->comment('排序權重');
            $table->tinyInteger('status')->default(0)->index()->comment('狀態 0:停用,1:啟用');
            $table->text('image_path')->nullable()->comment('圖片路徑');
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
        SchemaMonitor::dropIfExists('order_types');
    }
};

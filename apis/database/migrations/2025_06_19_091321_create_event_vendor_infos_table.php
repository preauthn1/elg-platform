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
        SchemaMonitor::create('event_vendor_infos', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 191)->nullable()->comment('商戶資訊名稱');
            $table->integer('vendor_id')->default(0)->comment('vendors.id');
            $table->string('currency', 191)->comment('幣種');
            $table->decimal('rate', 11, 5)->default(1)->comment('轉換率');
            $table->decimal('transfer_min_amount', 11, 5)->default(0.01)->comment('最小轉帳金額');
            $table->text('info')->comment('連接資訊');
            $table->string('image', 191)->nullable()->comment('圖片');
            $table->string('app_icon', 191)->nullable()->comment('app圖片');
            $table->integer('prepared_account_number')->default(0)->comment('預備帳號數量');
            $table->string('account_prefix', 10)->comment('帳號前綴');
            $table->boolean('status')->default(false)->comment('狀態0:停用,1:啟用');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('管理員編號');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('event_vendor_infos');
    }
};

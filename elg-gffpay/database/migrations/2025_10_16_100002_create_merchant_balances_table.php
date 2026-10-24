<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMerchantBalancesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('merchant_balances')) {
            return;
        }
        Schema::create('merchant_balances', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('merchant_id');
            $table->unsignedInteger('currency_id');
            $table->decimal('balance', 20, 9)->default(0)->comment('錢包餘額');
            $table->tinyInteger('status')->default(1)->comment('狀態 0:停用,1:啟用');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');

            // 索引
            $table->unique(['merchant_id', 'currency_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('merchant_balances');
    }
}

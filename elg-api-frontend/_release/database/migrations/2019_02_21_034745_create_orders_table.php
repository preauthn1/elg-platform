<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateOrdersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->integer('order_type_id')->comment('訂單類別(FK_OrderType)');
            $table->integer('currency_id')->comment('貨幣類別(FK_Currency)');
            $table->integer('user_id')->comment('帳號系統編號(User)');
            $table->integer('amount')->comment('金額');
            $table->integer('point')->comment('點數');
            $table->tinyInteger('status')->default(0)->comment('狀態 0:建立,1:成功,2:失敗');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('orders');
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrderLogsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('order_logs')) {
            return;
        }
        Schema::create('order_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id')->comment('orders.id');
            $table->tinyInteger('type')->default(1)->comment('類型 1:建立訂單 2:ton交易紀錄, 3:callback紀錄');
            $table->string('request_url', 191)->nullable()->comment('request url');
            $table->text('request_data')->nullable()->comment('request data');
            $table->text('response_data')->nullable()->comment('response data');
            $table->integer('http_code')->default(0)->comment('http code');
            $table->text('exception')->nullable()->comment('exception message');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');

            // 索引
            $table->index('order_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('order_logs');
    }
}

<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateNcpaysTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('ncpays', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('order_id')->comment('訂單系統編號');
            $table->integer('ncpay_deposit_id')->comment('存款編號');
            $table->integer('ncpay_order_id')->comment('訂單編號');
            $table->string('third_party_id',32)->comment('第三方訂單編號');
            $table->string('bank',16)->comment('銀行編號');
            $table->decimal('amount',10,2)->comment('金額');
            $table->text('remark')->comment('備註');
            $table->string('ncpay_status',16)->comment('狀態文字');
            $table->tinyInteger('status')->default(0)->comment('狀態1:待處理,2:處理中,3:成功,4:操作失敗,5:操作取消');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
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
        Schema::dropIfExists('ncpays');
    }
}

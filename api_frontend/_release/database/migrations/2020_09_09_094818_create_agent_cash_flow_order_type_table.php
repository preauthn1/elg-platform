<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAgentCashFlowOrderTypeTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('agent_cash_flow_order_types', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('agent_cash_flow_id')->nullable(false)->default(0)->comment('金流商戶資料編號')->index();
            $table->integer('order_type_id')->nullable(false)->default(0)->comment('支付方式編號')->index();
            $table->integer('min_amount')->nullable(false)->default(0)->comment('下限: 0為不限制');
            $table->integer('max_amount')->nullable(false)->default(0)->comment('上限: 0為不限制');
            $table->string('available_amount')->nullable(false)->default('')->comment('固額: 150,300,500');
            $table->integer('sorts')->nullable(false)->default(0)->comment('排序權重')->index();
            $table->tinyInteger('status')->nullable(false)->default(0)->comment('設置啟用狀態 0:停用, 1:啟用')->index();
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('agent_cash_flow_order_types');
    }
}

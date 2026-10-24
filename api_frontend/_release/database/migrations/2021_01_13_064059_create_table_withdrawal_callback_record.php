<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateTableWithdrawalCallbackRecord extends Migration
{     
    protected $table_name = 'withdrawal_payout_records';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable($this->table_name)) {
            Schema::create($this->table_name, function (Blueprint $table) {
                $table->increments('id')->comment('流水號');
                $table->integer('cash_flow_id')->nullable(false)->default(0)->comment('cash_flow_id_支付廠商ID(每家會有固定編號)');
                $table->integer('withdrawal_order_id')->comment('提款單ID');
                $table->string('transaction_no', 200)->comment('我方系統單號');
                $table->string('third_party_transaction_no', 200)->comment('三方系統單號');
                $table->decimal('result_amount', 18, 4)->comment('三方回應的金額');
                $table->string('result_code', 50)->comment('三方回應的交易狀態');
                $table->integer('is_success_payout')->default(0)->comment('是否定義為成功單');
                $table->string('transaction_time', 50)->comment('三方回應的交易狀態');
                $table->string('sign', 200)->comment('加密簽名');
                $table->text('payout_order_request_body')->comment('代付請求_request資訊');
                $table->text('payout_order_response')->comment('代付請求_response資訊');    
                $table->text('callback_request_body')->comment('原始request');
                $table->string('info_1', 200)->comment('額外資訊1');
                $table->string('info_2', 200)->comment('額外資訊2');
                $table->string('info_3', 200)->comment('額外資訊3');
                $table->dateTime('callback_time')
                    ->nullable()
                    ->comment('回調時間');
                $table->timestamp('created_at')
                    ->useCurrent()
                    ->comment('建立日期');
                $table->timestamp('updated_at')
                    ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                    ->comment('更新日期');
                $table->integer('admin')->default(0)->commit('更新者ID');
            });

        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}
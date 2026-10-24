<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateOrdersTable extends Migration
{
    protected $table_name = 'orders';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable($this->table_name)) {
            Schema::create($this->table_name, function (Blueprint $table) {
                $table->increments('id')->comment('系統編號');
                $table->string('order_no', 32)->unique()->comment('訂單編號');
                $table->string('third_party_order_no')->comment('三方金流訂單編號')->index();
                $table->integer('cash_flow_id')->nullable()->comment('支付商ID; cash_flows.id');
                $table->integer('agent_cash_flow_id')->nullable(false)->default(0)->comment('金流商戶資料編號')->index();
                $table->string('third_party_id', 100)->default('')->comment('支付商訂單號');
                $table->integer('order_type_id')->comment('訂單類別(FK_OrderType)');
                $table->integer('currency_id')->comment('貨幣類別(FK_Currency)');
                $table->integer('member_id')->comment('帳號系統編號(Members)');
                $table->decimal('amount', 18, 4)->comment('金額');
                $table->decimal('point', 18, 4)->comment('點數');
                $table->decimal('balance', 18, 4)->comment('儲值後餘額');
                $table->string('ip', 50)->default('')->comment('ip address');
                $table->string('callback')->nullable()->comment('回調網址');
                $table->smallInteger('status')->default(0)->comment('狀態 0:建立,1:成功,2:失敗,3:己上分');
                $table->string('remark', 200)->nullable(false)->default('')->comment('備註');
                $table->datetime('pay_time')->nullable()->comment('支付時間');
                $table->timestamp('created_at')->useCurrent()->comment('建立日期');
                $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))->comment('更新日期');
                $table->integer('admin')->comment('更新者ID');
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
        Schema::dropIfExists($this->table_name);
    }
}

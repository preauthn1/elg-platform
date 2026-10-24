<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ModifyOrdersColumns extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {
            //
            $table->integer('agent_cash_flow_id')->after('order_no')->nullable(false)->default(0)->comment('金流商戶資料編號')->index();
            $table->text('remark')->nullable(false)->default('')->comment('備註')->change();
            $table->decimal('amount', 18, 4)->change();
            $table->decimal('point', 18, 4)->change();
            $table->decimal('balance', 18, 4)->change();
            $table->smallInteger('status')->comment('狀態 0:建立,1:成功,2:失敗,3:己上分')->change();
            $table->unique('order_no');
            $table->string('third_party_order_no')->after('order_no')->comment('三方金流訂單編號')->index();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            //
            $table->dropColumn('agent_cash_flow_id');
            $table->dropColumn('remark');
            $table->dropUnique('orders_order_no_unique');
            $table->dropColumn('third_party_order_no');
        });
    }
}

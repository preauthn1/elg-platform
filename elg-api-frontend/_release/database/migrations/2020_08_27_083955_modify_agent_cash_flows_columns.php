<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ModifyAgentCashFlowsColumns extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('agent_cash_flows', function (Blueprint $table) {
            // 沒有數據才可以用dropColumn
            $table->dropColumn('agent_id');

            $table->decimal('rate', 10, 4)->after('cash_flow_id')->nullable(false)->default(0)->comment('轉換比例');
            $table->integer('agent_group_id')->after('cash_flow_id')->nullable(false)->default(0)->comment('代理群組編號')->index();
            $table->tinyInteger('status')->after('info')->nullable(false)->default(0)->comment('設置啟用狀態 0:停用, 1:啟用')->index();
            $table->integer('sorts')->after('info')->nullable(false)->default(0)->comment('排序權重')->index();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('agent_cash_flows', function (Blueprint $table) {
            //
            $table->dropColumn('rate');
            $table->dropColumn('agent_group_id');
            $table->dropColumn('status');
            $table->dropColumn('sorts');

            $table->integer('agent_id')->after('id')->nullable(false)->default(0)->comment('代理編號');
        });
    }
}

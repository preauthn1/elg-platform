<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenu202101151 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $query_parent_id = DB::table("agent_menus")->where("code" , "withdrawal_order_review")->first()->id;

        DB::table("agent_menus")
            ->insert(array(
                'code' => "withdrawal_order_mark_paid",
                'name' => '洗分管理_標記為已付款',
                'parent' => $query_parent_id ,
                'url' => '',
                'symbol' => '',
                'action' => '',
                'status' => 1,
                'sort' => -999,
                'admin' => 0
            ));

        DB::table("agent_menus")
            ->insert(array(
                'code' => "withdrawal_order_payout",
                'name' => '洗分管理_設定代付',
                'parent' => $query_parent_id ,
                'url' => '',
                'symbol' => '',
                'action' => '',
                'status' => 1,
                'sort' => -999,
                'admin' => 0
            ));

        DB::table("agent_menus")
            ->insert(array(
                'code' => "withdrawal_order_success_to_waiting",
                'name' => '洗分管理_將付款成功調整為等待付款',
                'parent' => $query_parent_id ,
                'url' => '',
                'symbol' => '',
                'action' => '',
                'status' => 1,
                'sort' => -999,
                'admin' => 0
            ));

        DB::table("agent_menus")
            ->insert(array(
                'code' => "withdrawal_order_payout_to_cancel",
                'name' => '洗分管理_取消代付等待中狀態',
                'parent' => $query_parent_id ,
                'url' => '',
                'symbol' => '',
                'action' => '',
                'status' => 1,
                'sort' => -999,
                'admin' => 0
            ));
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
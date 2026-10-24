<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenu202103191722 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $parent = DB::table("agent_menus")->where("code" , 'system_management')->first();

        DB::table("agent_menus")->where("code" , 'deposit_vendor_management')->update([ "sort" => 10 ]);

        DB::table("agent_menus")
            ->insert(array(
                'code' => 'deposit_order_types_list',
                'name' => '支付方式管理',
                'parent' => $parent->id,
                'url' => 'deposit/order_type_list',
                'symbol' => '',
                'action' => '',
                'sort' => 11,
                'status' => 1,
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
<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenu202103191722 extends Migration
{
    protected $menus = array(
        array(
            'code' => 'deposit_order_types_list',
            'name' => '支付方式管理',
            'parent' => 'system_management',  //直接用parent的code
            'url' => 'deposit/order_type_list',
            'symbol' => '',
            'action' => '',
            'sort' => 11,
            'status' => 1,
            'admin' => 0
        ),
        array(
            'code' => 'show_proxy_domain',
            'name' => '顯示代理網域',
            'parent' => 'agent_management',  //直接用parent的code
            'url' => '',
            'symbol' => '',
            'action' => '',
            'sort' => 999,
            'status' => 1,
            'admin' => 0
        ),
        array(
            'code' => 'cost_list',
            'name' => '成本設定',
            'parent' => 'system_management',  //直接用parent的code
            'url' => 'cost/index',
            'symbol' => '',
            'action' => '',
            'sort' => 80,
            'status' => 1,
            'admin' => 0
        )
    );

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        foreach ($this->menus as $menu) {
            DB::insert("call ADMIN_insert_agent_menus(" .
                "'" . $menu['code'] . "', " .
                "'" . $menu['name'] . "', " .
                "'" . $menu['parent'] . "', " .
                "'" . $menu['url'] . "', " .
                "'" . $menu['symbol'] . "', " .
                "'" . $menu['action'] . "', " .
                "" . $menu['status'] . ", " .
                "" . $menu['sort'] . ", " .
                "" . $menu['admin'] .
                ")");
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
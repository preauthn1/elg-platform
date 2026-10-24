<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenu0201 extends Migration
{
    protected $menus = array(
        array(
            'code' => 'transfer_limit',
            'name' => '代理限額',
            'parent' => 0,
            'url' => '',
            'symbol' => 'ti-harddrives',
            'action' => '',
            'status' => 1,
            'sort' => 120,
            'admin' => 0
        ),
        array(
            'code' => 'transfer_limit_management',
            'name' => '代理限額管理',
            'parent' => 'transfer_limit',  //直接用parent的code
            'url' => 'transfer_limit/index',
            'symbol' => '',
            'action' => '',
            'status' => 1,
            'sort' => 10,
            'admin' => 0
        ),
        array(
            'code' => 'point_record',
            'name' => '上下分紀錄',
            'parent' => 'transfer_limit',
            'url' => 'transfer_limit/record',
            'symbol' => '',
            'action' => '',
            'status' => 1,
            'sort' => 0,
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
        foreach($this->menus as $menu) {
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
        foreach($this->menus as $menu) {
            DB::insert("call ADMIN_delete_agent_menus('" . $menu['code'] . "')");
        }
    }
}

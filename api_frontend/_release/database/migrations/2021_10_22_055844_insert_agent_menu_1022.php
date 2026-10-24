<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenu1022 extends Migration
{
    protected $table_name = 'agent_menus';
    protected $menus = array(
        array(
            'code' => 'system_maintenance',
            'name' => '系統維護',
            'parent' => 0,  //直接用parent的code
            'url' => '',
            'symbol' => 'ti-lock',
            'action' => '',
            'status' => 1,
            'sort' => 130,
            'admin' => 0
        ),
        array(
            'code' => 'switch_and_whitelist',
            'name' => '開關及白名單',
            'parent' => 'system_maintenance',  //直接用parent的code
            'url' => 'maintance_whitelist/index',
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
        foreach ($this->menus as $menu) {
            DB::insert("call ADMIN_delete_agent_menus('" . $menu['code'] . "')");
        }
    }
}

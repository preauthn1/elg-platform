<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenu0810 extends Migration
{
    protected $menus = array(
        array(
            'code' => 'member_disabled_permissiom',
            'name' => '會員停用/啟用',
            'parent' => 'member',  //直接用parent的code
            'url' => '',
            'symbol' => '',
            'action' => '',
            'status' => 1,
            'sort' => -999,
            'admin' => 0
        ),
        array(
            'code' => 'agent_other_setting',
            'name' => '代理其他設置',
            'parent' => 'agent_management',  //直接用parent的code
            'url' => '',
            'symbol' => 'tag_invisible',
            'action' => '',
            'status' => 1,
            'sort' => 80,
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

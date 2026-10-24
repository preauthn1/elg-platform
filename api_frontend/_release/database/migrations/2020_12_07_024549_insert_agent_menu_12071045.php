<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenu12071045 extends Migration
{

    protected $menus = array(
        array(
            'code' => 'new_vendor',
            'name' => '新遊戲商管理',
            'parent' => '0',  //直接用parent的code
            'url' => '',
            'symbol' => 'ti-game',
            'action' => '',
            'status' => 1,
            'sort' => 100,
            'admin' => 0
        ),
        array(
            'code' => 'game_management',
            'name' => '遊戲管理',
            'parent' => 'new_vendor',
            'url' => 'game/index',
            'symbol' => '',
            'action' => '',
            'status' => 1,
            'sort' => 10,
            'admin' => 0
        ),
        array(
            'code' => 'game_type_management',
            'name' => '遊戲類別管理',
            'parent' => 'new_vendor',
            'url' => 'game_type/index',
            'symbol' => '',
            'action' => '',
            'status' => 1,
            'sort' => 20,
            'admin' => 0
        ),
        array(
            'code' => 'vendor_management',
            'name' => '廠商管理',
            'parent' => 'new_vendor',
            'url' => 'vendor_management/index',
            'symbol' => '',
            'action' => '',
            'status' => 1,
            'sort' => 30,
            'admin' => 0
        ),
        array(
            'code' => 'vendor_info_management',
            'name' => '商戶資料管理',
            'parent' => 'new_vendor',
            'url' => 'vendor_info/index',
            'symbol' => '',
            'action' => '',
            'status' => 1,
            'sort' => 40,
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

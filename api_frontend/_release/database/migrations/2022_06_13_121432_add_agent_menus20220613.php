<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddAgentMenus20220613 extends Migration
{
    protected $menus = array(
        array(
            'code' => 'v2Member',
            'name' => '玩家管理',
            'parent' => '0',  //直接用parent的code
            'url' => '',
            'symbol' => 'ti-user',
            'action' => '',
            'sort' => 210,
            'status' => 1,
            'admin' => 0
        ),
        array(
            'code' => 'v2MemberList',
            'name' => '玩家列表',
            'parent' => 'v2Member',
            'url' => 'v2Member/list',
            'symbol' => '',
            'action' => 'view,create,modify,delete',
            'sort' => 0,
            'status' => 1,
            'admin' => 0
        ),

        array(
            'code' => 'v2AdvertiseCarousel',
            'name' => '廣告輪播管理',
            'parent' => '0',
            'url' => '',
            'symbol' => 'ti-layout-slider-alt',
            'action' => '',
            'sort' => 220,
            'status' => 1,
            'admin' => 0
        ),
        array(
            'code' => 'v2AdvertiseCarouselList',
            'name' => '廣告輪播列表',
            'parent' => 'v2AdvertiseCarousel',
            'url' => 'v2advertise/list',
            'symbol' => '',
            'action' => 'create,modify,delete',
            'sort' => 0,
            'status' => 1,
            'admin' => 0
        ),

        array(
            'code' => 'v2GameCarousel',
            'name' => '遊戲輪播管理',
            'parent' => '0',
            'url' => '',
            'symbol' => 'ti-game',
            'action' => '',
            'sort' => 230,
            'status' => 1,
            'admin' => 0
        ),
        array(
            'code' => 'v2GameCarouselList',
            'name' => '遊戲輪播列表',
            'parent' => 'v2GameCarousel',
            'url' => 'v2GameCarousel/list',
            'symbol' => '',
            'action' => 'create,modify,delete',
            'sort' => 0,
            'status' => 1,
            'admin' => 0
        ),

        array(
            'code' => 'v2GameVendor',
            'name' => '遊戲商管理',
            'parent' => '0',
            'url' => '',
            'symbol' => 'ti-vendor',
            'action' => '',
            'sort' => 240,
            'status' => 1,
            'admin' => 0
        ),
        array(
            'code' => 'v2GameVendorList',
            'name' => '遊戲商列表',
            'parent' => 'v2GameVendor',
            'url' => 'v2GameVendor/list',
            'symbol' => '',
            'action' => 'create,modify,delete',
            'sort' => 0,
            'status' => 1,
            'admin' => 0
        ),

        array(
            'code' => 'v2Game',
            'name' => '遊戲管理',
            'parent' => '0',
            'url' => '',
            'symbol' => 'ti-game',
            'action' => '',
            'sort' => 250,
            'status' => 1,
            'admin' => 0
        ),
        array(
            'code' => 'v2GameList',
            'name' => '遊戲列表',
            'parent' => 'v2Game',
            'url' => 'v2Game/list',
            'symbol' => '',
            'action' => 'create,modify,delete',
            'sort' => 0,
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
        foreach($this->menus as $menu) {
            DB::insert("call ADMIN_delete_agent_menus('" . $menu['code'] . "')");
        }
    }
}

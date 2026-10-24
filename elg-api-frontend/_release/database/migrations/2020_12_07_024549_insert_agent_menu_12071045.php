<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenu12071045 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $parent = DB::table("agent_menus")
            ->insert(array(
                'code' => "new_vendor",
                'name' => '新遊戲商管理',
                'parent' => 0,
                'url' => '',
                'symbol' => 'ti-game',
                'action' => '',
                'status' => 1,
                'sort' => 100,
                'admin' => 0
            ));
        DB::table('agent_menus')
            ->insert(array(
                'code' => 'game_management',
                'name' => '遊戲管理',
                'parent' => $parent,
                'url' => 'game/index',
                'symbol' => ' ',
                'action' => ' ',
                'sort' => 10,
                'status' => 1,
                'admin' => 0
            ));
        DB::table('agent_menus')
            ->insert(array(
                'code' => 'game_type_management',
                'name' => '遊戲類別管理',
                'parent' => $parent,
                'url' => 'game_type/index',
                'symbol' => ' ',
                'action' => ' ',
                'sort' => 20,
                'status' => 1,
                'admin' => 0
            ));
        DB::table('agent_menus')
            ->insert(array(
                'code' => 'vendor_management',
                'name' => '廠商管理',
                'parent' => $parent,
                'url' => 'vendor_management/index',
                'symbol' => ' ',
                'action' => ' ',
                'sort' => 30,
                'status' => 1,
                'admin' => 0
            ));
        DB::table('agent_menus')
            ->insert(array(
                'code' => 'vendor_info_management',
                'name' => '商戶資料管理',
                'parent' => $parent,
                'url' => 'vendor_info/index',
                'symbol' => ' ',
                'action' => ' ',
                'sort' => 40,
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
        DB::table('agent_menus')->where('code', "new_vendor")->delete();
        DB::table('agent_menus')->where('code', "game_management")->delete();
        DB::table('agent_menus')->where('code', "game_type_management")->delete();
        DB::table('agent_menus')->where('code', "vendor_management")->delete();
        DB::table('agent_menus')->where('code', "vendor_info_management")->delete();
    }
}

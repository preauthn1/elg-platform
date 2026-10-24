<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertUserMenusTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::table('user_menus')->insert(array(
            'code' => 'user',
            'name' => '管理員管理',
            'parent' => '0',
            'url' => 'user',
            'symbol' => 'user',
            'action' => 'create,update,changePassword,delete',
            'admin' => 1
        ));
        DB::table('user_menus')->insert(array(
            'code' => 'agent',
            'name' => '代理管理',
            'parent' => '0',
            'url' => 'agent',
            'symbol' => 'agent',
            'action' => 'create,update,changePassword,delete',
            'admin' => 1
        ));
        DB::table('user_menus')->insert(array(
            'code' => 'game',
            'name' => '遊戲列表',
            'parent' => '0',
            'url' => 'game',
            'symbol' => 'game',
            'action' => 'list',
            'admin' => 1
        ));
        DB::table('user_menus')->insert(array(
            'code' => 'report',
            'name' => '報表管理',
            'parent' => '0',
            'url' => 'report',
            'symbol' => 'report',
            'action' => 'member,transaction,customer',
            'admin' => 1
        ));
        DB::table('user_menus')->insert(array(
            'code' => 'customer',
            'name' => '客服中心',
            'parent' => '0',
            'url' => 'customer',
            'symbol' => 'customer',
            'action' => 'message',
            'admin' => 1
        ));
        DB::table('user_menus')->insert(array(
            'code' => 'agent_management',
            'name' => '代理管理',
            'parent' => '2',
            'url' => 'agent_management',
            'symbol' => '',
            'action' => '',
            'admin' => 1
        ));
        DB::table('user_menus')->insert(array(
            'code' => 'agent_permission',
            'name' => '代理權限',
            'parent' => '2',
            'url' => 'agent_permission',
            'symbol' => '',
            'action' => '',
            'admin' => 1
        ));
        DB::table('user_menus')->insert(array(
            'code' => 'game_list',
            'name' => '遊戲列表',
            'parent' => '3',
            'url' => 'game_list',
            'symbol' => '',
            'action' => '',
            'admin' => 1
        ));
        DB::table('user_menus')->insert(array(
            'code' => 'member_report',
            'name' => '會員報表',
            'parent' => '4',
            'url' => 'member_report',
            'symbol' => '',
            'action' => '',
            'admin' => 1
        ));
        DB::table('user_menus')->insert(array(
            'code' => 'transaction_report',
            'name' => '轉點報表',
            'parent' => '4',
            'url' => 'transaction_report',
            'symbol' => '',
            'action' => '',
            'admin' => 1
        ));
        DB::table('user_menus')->insert(array(
            'code' => 'customer_report',
            'name' => '客服報表',
            'parent' => '4',
            'url' => 'customer_report',
            'symbol' => '',
            'action' => '',
            'admin' => 1
        ));
    }
}

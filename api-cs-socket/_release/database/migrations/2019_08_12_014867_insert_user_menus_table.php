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
            'code' => 'index',
            'name' => 'DashBoard',
            'parent' => '0',
            'url' => '/index',
            'symbol' => 'dashboard',
            'action' => '',
            'admin' => 0
        ));
        DB::table('user_menus')->insert(array(
            'code' => 'agent',
            'name' => '營運商管理',
            'parent' => '0',
            'url' => '/agent',
            'symbol' => 'view_compact',
            'action' => '',
            'admin' => 0
        ));
        DB::table('user_menus')->insert(array(
            'code' => 'customer_service',
            'name' => '客服人員管理',
            'parent' => '0',
            'url' => '/customer_service',
            'symbol' => 'list',
            'action' => '',
            'admin' => 0
        ));
        DB::table('user_menus')->insert(array(
            'code' => 'chat',
            'name' => '聊天',
            'parent' => '0',
            'url' => '/chat',
            'symbol' => 'chat_bubble',
            'action' => '',
            'admin' => 0
        ));
        DB::table('user_menus')->insert(array(
            'code' => 'transaction_log',
            'name' => '紀錄查詢',
            'parent' => '0',
            'url' => '/transaction/list',
            'symbol' => 'email',
            'action' => '',
            'admin' => 1
        ));
        DB::table('user_menus')->insert(array(
            'code' => 'setup',
            'name' => '設定',
            'parent' => '0',
            'url' => '',
            'symbol' => 'widgets',
            'action' => '',
            'admin' => 1
        ));
        DB::table('user_menus')->insert(array(
            'code' => 'file',
            'name' => '技術文件',
            'parent' => '0',
            'url' => '',
            'symbol' => 'tune',
            'action' => '',
            'admin' => 1
        ));
        DB::table('user_menus')->insert(array(
            'code' => 'system_setup',
            'name' => '系統設定',
            'parent' => '6',
            'url' => '/setup/system',
            'symbol' => '',
            'action' => '',
            'admin' => 1
        ));
        DB::table('user_menus')->insert(array(
            'code' => 'my_setup',
            'name' => '我的設定',
            'parent' => '6',
            'url' => '/setup/self',
            'symbol' => '',
            'action' => '',
            'admin' => 1
        ));
        DB::table('user_menus')->insert(array(
            'code' => 'api',
            'name' => '會員串接說明(API)',
            'parent' => '7',
            'url' => '/file/api',
            'symbol' => '',
            'action' => '',
            'admin' => 1
        ));
    }
}

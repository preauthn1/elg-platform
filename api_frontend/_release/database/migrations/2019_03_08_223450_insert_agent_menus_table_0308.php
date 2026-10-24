<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenusTable0308 extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('agent_menus', function (Blueprint $table) {
            $table->boolean('status')
                ->after('action')
                ->nullable()
                ->default(0)
                ->comment('狀態');
        });
        DB::table('agent_menus')->insert(array(
            'id' => 1,
            'code' => 'system_management',
            'name' => '系統管理管理',
            'parent' => '0',
            'url' => '',
            'symbol' => 'ti-file',
            'action' => '',
            'sort' => 10,
            'status' => 1,
            'admin' => 0
        ));
        DB::table('agent_menus')->insert(array(
            'id' => 2,
            'code' => 'validation',
            'name' => '會員認證選項管理',
            'parent' => '1',
            'url' => 'validation/index',
            'symbol' => '',
            'action' => '',
            'status' => 0,
            'admin' => 1
        ));
        DB::table('agent_menus')->insert(array(
            'id' => 3,
            'code' => 'domain',
            'name' => '網域/版型管理',
            'parent' => '1',
            'url' => 'domain/index',
            'symbol' => '',
            'action' => '',
            'status' => 1,
            'admin' => 0
        ));
        DB::table('agent_menus')->insert(array(
            'id' => 4,
            'code' => 'agent_management',
            'name' => '下線代理管理',
            'parent' => '0',
            'url' => '',
            'symbol' => 'ti-marker-alt',
            'action' => '',
            'sort' => 20,
            'status' => 1,
            'admin' => 0
        ));
        DB::table('agent_menus')->insert(array(
            'id' => 5,
            'code' => 'agent',
            'name' => '帳號管理',
            'parent' => '4',
            'url' => 'agent/index',
            'symbol' => '',
            'action' => '',
            'status' => 1,
            'admin' => 0
        ));
        DB::table('agent_menus')->insert(array(
            'id' => 6,
            'code' => 'add_agent',
            'name' => '建立營運商',
            'parent' => '4',
            'url' => 'agent/edit',
            'symbol' => '',
            'action' => '',
            'status' => 1,
            'admin' => 0
        ));
        DB::table('agent_menus')->insert(array(
            'id' => 7,
            'code' => 'member_management',
            'name' => '下線會員管理',
            'parent' => '0',
            'url' => '',
            'symbol' => 'ti-palette',
            'action' => '',
            'sort' => 30,
            'status' => 1,
            'admin' => 0
        ));
        DB::table('agent_menus')->insert(array(
            'id' => 8,
            'code' => 'member',
            'name' => '帳號管理',
            'parent' => '7',
            'url' => 'member/index',
            'symbol' => '',
            'action' => '',
            'status' => 1,
            'admin' => 0
        ));
        DB::table('agent_menus')->insert(array(
            'id' => 9,
            'code' => 'add_member',
            'name' => '建立會員',
            'parent' => '7',
            'url' => 'member/edit',
            'symbol' => '',
            'action' => '',
            'status' => 1,
            'admin' => 0
        ));
        DB::table('agent_menus')->insert(array(
            'id' => 10,
            'code' => 'order',
            'name' => '儲值訂單查詢',
            'parent' => '7',
            'url' => 'member/order',
            'symbol' => '',
            'action' => '',
            'status' => 1,
            'admin' => 0
        ));
        DB::table('agent_menus')->insert(array(
            'id' => 11,
            'code' => 'statistics',
            'name' => '統計報表查詢',
            'parent' => '0',
            'url' => '',
            'symbol' => 'ti-map-alt',
            'action' => '',
            'status' => 1,
            'sort' => 40,
            'admin' => 0
        ));
        DB::table('agent_menus')->insert(array(
            'id' => 12,
            'code' => 'deposit',
            'name' => '儲值報表',
            'parent' => '11',
            'url' => 'statistics/deposit',
            'symbol' => '',
            'action' => '',
            'status' => 0,
            'admin' => 0
        ));
        DB::table('agent_menus')->insert(array(
            'id' => 13,
            'code' => 'transaction',
            'name' => '轉點報表',
            'parent' => '11',
            'url' => 'statistics/transaction',
            'symbol' => '',
            'action' => '',
            'status' => 0,
            'admin' => 0
        ));
        DB::table('agent_menus')->insert(array(
            'id' => 14,
            'code' => 'register',
            'name' => '會員註冊報表',
            'parent' => '11',
            'url' => 'statistics/register',
            'symbol' => '',
            'action' => '',
            'status' => 0,
            'admin' => 0
        ));
        DB::table('agent_menus')->insert(array(
            'id' => 15,
            'code' => 'vendor',
            'name' => '遊戲商管理',
            'parent' => '1',
            'url' => 'vendor/index',
            'symbol' => '',
            'action' => '',
            'status' => 1,
            'admin' => 0
        ));
        DB::table('agent_menus')->insert(array(
            'id' => 16,
            'code' => 'cash_flow',
            'name' => '金流商管理',
            'parent' => '1',
            'url' => 'cash_flow/index',
            'symbol' => '',
            'action' => '',
            'status' => 0,
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
        // Schema::dropIfExists('member_login_logs');
    }
}
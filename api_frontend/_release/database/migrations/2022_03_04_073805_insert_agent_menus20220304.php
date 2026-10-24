<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenus20220304 extends Migration
{
    protected $menus = array(
        array(
            'code' => 'update_agent_group_permissions',
            'name' => '組別編輯權限',
            'parent' => 'agent_management',  //直接用parent的code
            'url' => '',
            'symbol' => '',
            'action' => '',
            'status' => 1,
            'sort' => -999,
            'admin' => 0
        ),
        array(
            'code' => 'report_sr17',
            'name' => 'SR17 W-VJackpot',
            'parent' => 'statistics',
            'url' => '',
            'symbol' => '',
            'action' => '',
            'status' => 1,
            'sort' => 170,
            'admin' => 0
        ),
        array(
            'code' => 'sr17_period',
            'name' => 'sr17 period',
            'parent' => 'report_sr17',
            'url' => 'report/sr17_period',
            'symbol' => '',
            'action' => '',
            'status' => 1,
            'sort' => 0,
            'admin' => 0
        ),
        array(
            'code' => 'sr17_member_search',
            'name' => 'sr17 member search',
            'parent' => 'report_sr17',
            'url' => 'report/sr17_member_search',
            'symbol' => '',
            'action' => '',
            'status' => 1,
            'sort' => 0,
            'admin' => 0
        ),
        array(
            'code' => 'sr17_ticket_search',
            'name' => 'sr17 ticket search',
            'parent' => 'report_sr17',
            'url' => 'report/sr17_ticket_search',
            'symbol' => '',
            'action' => '',
            'status' => 1,
            'sort' => 0,
            'admin' => 0
        ),
        array(
            'code' => 'sr17_manual_draw',
            'name' => 'sr17 manual draw',
            'parent' => 'report_sr17',
            'url' => '',
            'symbol' => '',
            'action' => '',
            'status' => 1,
            'sort' => -999,
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

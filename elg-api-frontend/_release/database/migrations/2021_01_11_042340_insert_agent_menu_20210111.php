<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenu20210111 extends Migration
{
    protected $menus = array(
        array(
            'code' => 'point_barrel',
            'name' => '分桶/分桶/PS',
            'parent' => 0,
            'url' => '',
            'symbol' => 'ti-layout-grid4-alt',
            'action' => '',
            'status' => 1,
            'sort' => 110,
            'admin' => 0
        ),
        array(
            'code' => 'ps_share_setting',
            'name' => '持股份設定',
            'parent' => 'point_barrel',  //直接用parent的code
            'url' => 'point_barrel/share_setting',
            'symbol' => '',
            'action' => '',
            'status' => 1,
            'sort' => 0,
            'admin' => 0
        ),
        array(
            'code' => 'ps_performance_query',
            'name' => '業績查詢',
            'parent' => 'point_barrel',
            'url' => 'point_barrel/performance_query',
            'symbol' => '',
            'action' => '',
            'status' => 1,
            'sort' => 0,
            'admin' => 0
        ),
        array(
            'code' => 'ps_cost_setting',
            'name' => '輸入成本',
            'parent' => 'point_barrel',
            'url' => 'point_barrel/cost_setting',
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

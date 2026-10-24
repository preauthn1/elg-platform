<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenus20211228 extends Migration
{
    protected $menus = array(
        array(
            'code' => 'report_ssr02',
            'name' => 'SSR02 開洗分明細表New',
            'parent' => 'statistics',  //直接用parent的code
            'url' => 'report/ssr02',
            'symbol' => '',
            'action' => '',
            'sort' => 11,
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

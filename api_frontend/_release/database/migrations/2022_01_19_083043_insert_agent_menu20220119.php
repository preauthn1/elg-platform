<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenu20220119 extends Migration
{
    protected $menus = array(
        array(
            'code' => 'notice_voice',
            'name' => '自助上分(洗分)提示音',
            'parent' => 'system_management',  //直接用parent的code
            'url' => '',
            'symbol' => '',
            'action' => '',
            'sort' => -999,
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

<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenu1101 extends Migration
{
    protected $table_name = 'agent_menus';
    protected $menus = array(
        array(
            'code' => 'mobile_validate_permission',
            'name' => '修改手機認證權限',
            'parent' => 'member',  //直接用parent的code
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
        foreach ($this->menus as $menu) {
            DB::insert("call ADMIN_delete_agent_menus('" . $menu['code'] . "')");
        }
    }
}

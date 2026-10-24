<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenu10201147 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::table("agent_menus")
            ->insert(array(
                'code' => "agent_group",
                'name' => '代理組別管理',
                'parent' => 4,
                'url' => 'agent/group_list',
                'symbol' => '',
                'action' => '',
                'status' => 1,
                'sort' => 95,
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
        //
    }
}

<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenu10151810 extends Migration
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
                'code' => "agent_advance_setting",
                'name' => '代理進階設置',
                'parent' => 4,
                'url' => '',
                'symbol' => 'tag_invisible',
                'action' => '',
                'status' => 1,
                'sort' => 75,
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

<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenu11051505 extends Migration
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
                'code' => "agent_sync_permissiom",
                'name' => '代理子帳號權限同步',
                'parent' => 4,
                'url' => 'agent/sync_agent_permission',
                'symbol' => '',
                'action' => '',
                'status' => 1,
                'sort' => 100,
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

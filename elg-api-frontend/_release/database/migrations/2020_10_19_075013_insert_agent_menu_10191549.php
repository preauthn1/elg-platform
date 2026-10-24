<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenu10191549 extends Migration
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
            'code' => "agent_operate_logs",
            'name' => '代理操作紀錄',
            'parent' => 4,
            'url' => 'records/agent_operate_logs',
            'symbol' => '',
            'action' => '',
            'status' => 1,
            'sort' => 85,
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
